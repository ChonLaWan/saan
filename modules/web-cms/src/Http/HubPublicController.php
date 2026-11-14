<?php

namespace Saan\Modules\WebCms\Http;

use App\Http\Controllers\SchoolWebsiteController;
use App\Support\Saan\NetKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Saan\Modules\WebCms\Downloads;
use Saan\Modules\WebCms\Hubs;

/** F4 public pages (template "group hub") and the counting download route. Reads only: nothing here writes except the aggregate download counter. */
class HubPublicController
{
    private function hub(string $slug): object
    {
        return Hubs::published($slug) ?? abort(404);
    }

    /** Layout context of the school site plus the hub data. $preview = internal preview: drafts included, marked. */
    public function data(object $hub, bool $preview = false): array
    {
        $ctx = app(SchoolWebsiteController::class)->context($preview);
        $boxes = Hubs::boxes($hub, Hubs::tree((int) $hub->id, $preview), (int) $hub->box_limit, $preview);
        $staff = Hubs::staff($hub);
        return array_replace($ctx, ['hub' => $hub, 'boxes' => $boxes, 'heads' => $staff['heads'], 'members' => $staff['members'], 'news' => Hubs::news($hub, (int) $hub->news_limit),
            'hasNews' => $hub->news_category || $hub->news_tag, 'pageTitle' => $hub->title, 'hubPreview' => $preview,
            'committee' => Hubs::committee($hub)]);
    }

    public function index()
    {
        $ctx = app(SchoolWebsiteController::class)->context();
        return view('web-cms::hub.index', array_replace($ctx, ['hubs' => Hubs::listPublished(), 'kinds' => Hubs::KINDS, 'pageTitle' => 'กลุ่มงาน กลุ่มสาระ สมาคม และรายงาน']));
    }

    public function show(string $slug)
    {
        return view('web-cms::hub.show', $this->data($this->hub($slug)));
    }

    /** "ดูทั้งหมด" of one box: paged, filter by year (พ.ศ.) and title. */
    public function docs(Request $r, string $slug, int $cat)
    {
        $hub = $this->hub($slug);
        $category = Hubs::category($hub, $cat) ?? abort(404);
        $f = $r->validate(['q' => 'nullable|string|max:100', 'year' => 'nullable|integer|between:2400,2700', 'sub' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1',
            'fy' => 'nullable|integer|between:2400,2701', 'month' => 'nullable|integer|between:1,12']);
        if (isset($f['q']) && trim($f['q']) !== '') $this->throttleSearch($r);
        $ctx = app(SchoolWebsiteController::class)->context();
        if ($category->rule === 'monthly') { // F5b: monthly reports, one fiscal year at a time, missing months shown as not published
            return view('web-cms::hub.monthly', array_replace($ctx, ['hub' => $hub, 'category' => $category, 'm' => Hubs::monthly($hub, $category, $f), 'pageTitle' => $category->label.' — '.$hub->title, 'hubPreview' => false]));
        }
        return view('web-cms::hub.docs', array_replace($ctx, ['hub' => $hub, 'category' => $category, 'docs' => Hubs::paged($hub, $category, $f), 'years' => Hubs::years($hub, $category), 'f' => $f,
            'pageTitle' => $category->label.' — '.$hub->title, 'hubPreview' => false]));
    }

    public function news(string $slug)
    {
        $hub = $this->hub($slug);
        abort_unless($hub->news_category || $hub->news_tag, 404);
        $ctx = app(SchoolWebsiteController::class)->context();
        return view('web-cms::hub.news', array_replace($ctx, ['hub' => $hub, 'posts' => Hubs::newsOrder(Hubs::newsQuery($hub), $hub)->paginate(12)->withQueryString(),
            'pageTitle' => 'ข่าว '.$hub->title]));
    }

    /** GET /portal/d/{id}: count (no personal data, per-network daily ceiling in cache), then stream the file or redirect to the document's stored https link. */
    public function download(Request $r, int $id)
    {
        $doc = Hubs::downloadable($id) ?? abort(404);
        return Downloads::serve($r, $doc);
    }

    /** Title search reads the table: 30 searches a minute per client (IPv6 by /64), like the site search. */
    private function throttleSearch(Request $r): void
    {
        $key = 'hub-search:'.NetKey::rate($r->ip());
        if (RateLimiter::tooManyAttempts($key, 30)) abort(429, 'ค้นหาถี่เกินไป กรุณารอสักครู่', ['Retry-After' => RateLimiter::availableIn($key)]);
        RateLimiter::hit($key, 60);
    }
}
