<?php

namespace Saan\Modules\WebIta;

use App\Domain\Cms\SafeContent;
use App\Domain\Cms\WebsiteContent;
use App\Domain\SchoolOperations;
use Illuminate\Support\Facades\DB;

class ItaRepository
{
    /** Neutral starter headings. Schools must edit them to match the ITA announcement of that fiscal year. */
    public const STARTER = [
        ['code' => '', 'title' => 'ข้อมูลพื้นฐานของหน่วยงาน'],
        ['code' => '', 'title' => 'การบริหารงานและแผนดำเนินงาน'],
        ['code' => '', 'title' => 'การจัดซื้อจัดจ้างและการจัดหาพัสดุ'],
        ['code' => '', 'title' => 'การบริหารและพัฒนาทรัพยากรบุคคล'],
        ['code' => '', 'title' => 'การส่งเสริมความโปร่งใสและการรับเรื่องร้องเรียน'],
        ['code' => '', 'title' => 'มาตรการป้องกันการทุจริต'],
    ];

    public function __construct(private SchoolOperations $ops, private WebsiteContent $content) {}

    public function authorize(): void { $this->content->authorize(); }

    public function years(): array
    {
        $this->authorize();
        return DB::table('ita_years')->where('school_id', $this->ops->school())->orderByDesc('fiscal_year')->get()
            ->map(fn ($y) => (array) $y + ['items' => DB::table('ita_items')->join('ita_topics', 'ita_topics.id', '=', 'ita_items.topic_id')->where('ita_topics.year_id', $y->id)->count()])->all();
    }

    public function read(int $id, bool $publicOnly = false, bool $resolve = true): array
    {
        return ItaSources::scoped(fn () => $this->readRows($id, $publicOnly, $resolve));
    }

    private function readRows(int $id, bool $publicOnly, bool $resolve): array
    {
        $year = DB::table('ita_years')->where('school_id', $publicOnly ? (int) config('school.school_id') : $this->ops->school())->where('id', $id)
            ->when($publicOnly, fn ($q) => $q->where('status', 'published'))->first() ?? abort(404);
        $topics = DB::table('ita_topics')->where('year_id', $year->id)->orderBy('sort')->orderBy('id')->get();
        $items = DB::table('ita_items')->whereIn('topic_id', $topics->pluck('id'))->orderBy('sort')->orderBy('id')->get()->groupBy('topic_id');
        $media = DB::table('cms_media')->where('school_id', $year->school_id)->whereIn('id', $items->flatten()->pluck('media_id')->filter()->unique())->get(['id', 'original_name', 'mime', 'bytes'])->keyBy('id');
        return ['year' => (array) $year, 'topics' => $topics->map(fn ($t) => [
            'id' => $t->id, 'code' => $t->code, 'title' => $t->title,
            'items' => ($items[$t->id] ?? collect())->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'url' => $i->url, 'media_id' => $i->media_id, 'note' => $i->note,
                'source' => $i->source ?? null, 'source_opts' => ItaSources::parseOpts($i->source_opts ?? null), 'resolved' => self::resolved($i, (int) $year->fiscal_year, $resolve),
                'media' => $i->media_id && isset($media[$i->media_id]) ? (array) $media[$i->media_id] : null])->values()->all(),
        ])->values()->all()] + ($publicOnly ? [] : ['sources' => ItaSources::KEYS, 'source_fields' => ItaSources::OPTS]);
    }

    /** How an item reads on the page: manual content (file or link) wins over an automatic source; mode none = nothing to link. */
    private static function resolved(object $i, int $fy, bool $resolve = true): array
    {
        if ($i->media_id || (string) $i->url !== '') return ['mode' => 'manual'];
        if (empty($i->source)) return ['mode' => 'none'];
        if (!$resolve) return ['mode' => 'auto', 'ok' => false, 'path' => null, 'evidence' => '', 'reason' => '', 'extra' => [], 'yearly' => false];
        return ['mode' => 'auto'] + ItaSources::resolve((string) $i->source, ItaSources::parseOpts($i->source_opts ?? null), $fy);
    }

    public function create(int $fiscalYear, ?int $copyFrom, bool $preset = false): array
    {
        $this->authorize();
        // read() (which resolves every automatic source) runs after the commit, not while the rows are locked
        $id = DB::transaction(function () use ($fiscalYear, $copyFrom, $preset) {
            $school = $this->ops->school();
            abort_if(DB::table('ita_years')->where('school_id', $school)->where('fiscal_year', $fiscalYear)->exists(), 409, 'มีข้อมูล ITA ของปีงบประมาณนี้แล้ว');
            $id = DB::table('ita_years')->insertGetId(['school_id' => $school, 'fiscal_year' => $fiscalYear, 'title' => 'การเปิดเผยข้อมูลสาธารณะ (ITA) ปีงบประมาณ '.$fiscalYear, 'status' => 'draft', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            if ($copyFrom) {
                $source = $this->read($copyFrom, false, false);
                // Copy the structure (topics and item titles) but not links/files: each year needs its own evidence.
                $this->writeTopics($id, $school, array_map(fn ($t) => ['code' => $t['code'], 'title' => $t['title'], 'items' => array_map(fn ($i) => ['title' => $i['title'], 'url' => null, 'media_id' => null, 'note' => '', 'source' => $i['source'] ?? null, 'source_opts' => $i['source_opts'] ?? null], $t['items'])], $source['topics']));
            } elseif ($preset) {
                $this->writeTopics($id, $school, ItaCatalog::preset());
            } else {
                $this->writeTopics($id, $school, array_map(fn ($t) => $t + ['items' => []], self::STARTER));
            }
            $this->ops->audit('ita.year_created', 'ita_years', $id, ['fiscal_year' => $fiscalYear, 'copied_from' => $copyFrom, 'preset' => $preset]);
            return $id;
        });
        return $this->read($id);
    }

    public function save(int $id, int $version, string $title, array $topics): array
    {
        $this->authorize();
        $mediaIds = collect($topics)->flatMap(fn ($t) => array_column($t['items'] ?? [], 'media_id'))->filter()->map(fn ($v) => (int) $v)->unique()->sort()->values();
        foreach ($mediaIds as $mediaId) $this->ops->record('cms_media', $mediaId);
        foreach ($topics as $t) foreach ($t['items'] ?? [] as $i) if (!empty($i['url'])) {
            abort_unless(SafeContent::url($i['url']), 422, 'ลิงก์ต้องเป็น https หรือเส้นทางในเว็บไซต์: '.$i['title']);
            abort_if(ItaCheck::internalPath($i['url']), 422, ItaCheck::INTERNAL_LINK.': '.$i['title']);
        }
        DB::transaction(function () use ($id, $version, $title, $topics, $mediaIds) {
            $school = $this->ops->school();
            $year = DB::table('ita_years')->where('school_id', $school)->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_if((int) $year->version !== $version, 409, 'มีการแก้ข้อมูล ITA จากอีกหน้าจอ กรุณาโหลดใหม่');
            foreach ($mediaIds as $mediaId) $this->ops->query('cms_media')->where('id', $mediaId)->lockForUpdate()->first() ?? abort(404);
            DB::table('ita_items')->whereIn('topic_id', DB::table('ita_topics')->where('year_id', $id)->pluck('id'))->delete();
            DB::table('ita_topics')->where('year_id', $id)->delete();
            $this->writeTopics($id, $school, $topics);
            DB::table('ita_years')->where('id', $id)->update(['title' => $title, 'version' => $version + 1, 'updated_at' => now()]);
            $this->ops->audit('ita.saved', 'ita_years', $id, ['version' => $version + 1, 'topics' => count($topics)]);
        });
        return $this->read($id);
    }

    public function setStatus(int $id, int $version, bool $publish): array
    {
        $this->authorize();
        DB::transaction(function () use ($id, $version, $publish) {
            $year = DB::table('ita_years')->where('school_id', $this->ops->school())->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            abort_if((int) $year->version !== $version, 409, 'มีการแก้ข้อมูล ITA จากอีกหน้าจอ กรุณาโหลดใหม่');
            DB::table('ita_years')->where('id', $id)->update(['status' => $publish ? 'published' : 'draft', 'published_at' => $publish ? ($year->published_at ?? now()) : $year->published_at, 'version' => $version + 1, 'updated_at' => now()]);
            $this->ops->audit($publish ? 'ita.published' : 'ita.unpublished', 'ita_years', $id, ['version' => $version + 1]);
        });
        return $this->read($id);
    }

    /** Media ids that guests may open: items of published years. */
    public static function publicMediaIds(int $school): array
    {
        return DB::table('ita_items')->join('ita_topics', 'ita_topics.id', '=', 'ita_items.topic_id')->join('ita_years', 'ita_years.id', '=', 'ita_topics.year_id')
            ->where('ita_years.school_id', $school)->where('ita_years.status', 'published')->whereNotNull('ita_items.media_id')->pluck('ita_items.media_id')->map(fn ($v) => (int) $v)->all();
    }

    public static function usesMedia(int $school, int $mediaId): bool
    {
        return DB::table('ita_items')->where('school_id', $school)->where('media_id', $mediaId)->exists();
    }

    public static function fingerprint(int $school): string
    {
        $y = DB::table('ita_years')->where('school_id', $school)->selectRaw('COUNT(*) c, COALESCE(SUM(version),0) v')->first();
        return $y->c.'-'.$y->v;
    }

    private static function cleanSource(mixed $s): ?string
    {
        return is_string($s) && isset(ItaSources::KEYS[$s]) ? $s : null;
    }

    private static function cleanSourceOpts(mixed $source, mixed $opts): ?string
    {
        if (is_string($opts)) $opts = ItaSources::parseOpts($opts);
        $clean = ItaSources::cleanOpts(self::cleanSource($source), $opts);
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : null;
    }

    private function writeTopics(int $yearId, int $school, array $topics): void
    {
        foreach (array_values($topics) as $ti => $t) {
            $topicId = DB::table('ita_topics')->insertGetId(['school_id' => $school, 'year_id' => $yearId, 'code' => (string) ($t['code'] ?? ''), 'title' => $t['title'], 'sort' => $ti, 'created_at' => now(), 'updated_at' => now()]);
            foreach (array_values($t['items'] ?? []) as $ii => $i) {
                DB::table('ita_items')->insert(['school_id' => $school, 'topic_id' => $topicId, 'title' => $i['title'], 'url' => ($i['url'] ?? '') ?: null, 'media_id' => !empty($i['media_id']) ? (int) $i['media_id'] : null, 'note' => (string) ($i['note'] ?? ''), 'source' => self::cleanSource($i['source'] ?? null), 'source_opts' => self::cleanSourceOpts($i['source'] ?? null, $i['source_opts'] ?? null), 'sort' => $ii, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
