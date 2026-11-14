<?php
namespace App\Domain;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SchoolOperations {
    public function school(): int { return (int)auth()->user()->school_id; }
    public function query(string $table) { return DB::table($table)->where('school_id',$this->school()); }
    public function role(array $roles): void { abort_unless(in_array(auth()->user()->role,$roles),403,'สิทธิ์ไม่เพียงพอ'); }
    public function classroom(int $id): void {
        abort_unless($this->query('classrooms')->where('id',$id)->exists(),404);
        abort_unless(in_array(auth()->user()->role,['admin','principal']) || (int)auth()->user()->classroom_id===$id,403,'อยู่นอกห้องที่รับผิดชอบ');
    }
    public function record(string $table, int $id) { return $this->query($table)->where('id',$id)->first() ?? abort(404); }
    /** Audit when the request may be a guest (password links): actor is the signed-in user, else the account concerned. */
    public function auditAs(int $fallbackActor,string $action,string $entity,?int $id,array $detail=[]): void {
        DB::table('audit_events')->insert(['school_id'=>(int)config('school.school_id'),'actor_id'=>auth()->id()??$fallbackActor,'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'detail'=>json_encode($detail,JSON_UNESCAPED_UNICODE),'created_at'=>now()]);
    }
    public function audit(string $action,string $entity,?int $id,array $detail=[]): void {
        DB::table('audit_events')->insert(['school_id'=>$this->school(),'actor_id'=>auth()->id(),'action'=>$action,'entity'=>$entity,'entity_id'=>$id,'detail'=>json_encode($detail,JSON_UNESCAPED_UNICODE),'created_at'=>now()]);
    }
    /** Lessons that still exist: the timetable module soft-deletes (lessons.deleted_at). */
    public function liveLessons() { $q=$this->query('lessons'); return \Illuminate\Support\Facades\Schema::hasColumn('lessons','deleted_at')?$q->whereNull('deleted_at'):$q; }
    private function overlap(string $a,string $b,string $c,string $d): bool { return $a<$d && $c<$b; }
    /**
     * dutyAvailable() for many teachers at once with a fixed number of queries (term, leaves, duties, lessons, bell periods).
     * Same checks and same order as the single version. Returns [teacher_id => reason|null].
     */
    public function dutyAvailableMany(array $teacherIds,string $date,string $start,string $end): array {
        $teacherIds=array_values(array_unique(array_map('intval',$teacherIds)));$out=array_fill_keys($teacherIds,null);
        if(!$teacherIds) return $out;
        $term=\App\Support\Saan\TermScope::current();
        if(!$term) return array_map(fn()=>'ยังไม่ได้ตั้งภาคเรียน',$out);
        if($date<$term->starts_on || $date>$term->ends_on) return array_map(fn()=>'วันที่อยู่นอกภาคเรียนสาธิต',$out);
        $leave=array_flip($this->query('leave_requests')->whereIn('teacher_id',$teacherIds)->where('status','approved')->where('starts_on','<=',$date)->where('ends_on','>=',$date)->pluck('teacher_id')->all());
        $busy=[];foreach($this->query('duties')->whereIn('teacher_id',$teacherIds)->where('date',$date)->where('status','assigned')->get(['teacher_id','starts_at','ends_at']) as $d) if($this->overlap($start,$end,$d->starts_at,$d->ends_at)) $busy[$d->teacher_id]=true;
        $day=CarbonImmutable::parse($date)->isoWeekday();$lessons=[];
        if($day<=5){
            $rows=$this->liveLessons()->where('term_id',$term->id)->whereIn('teacher_id',$teacherIds)->where('weekday',$day)->get(['teacher_id','period_id']);
            $periods=$rows->isEmpty()?collect():$this->query('bell_periods')->whereIn('id',$rows->pluck('period_id')->unique()->all())->get(['id','starts_at','ends_at'])->keyBy('id');
            foreach($rows as $l){ $p=$periods[$l->period_id]??abort(404); if($this->overlap($start,$end,$p->starts_at,$p->ends_at)) $lessons[$l->teacher_id]=true; }
        }
        foreach($teacherIds as $id) $out[$id]=isset($leave[$id])?'ครูมีวันลาที่อนุมัติแล้ว':(isset($busy[$id])?'ครูมีเวรทับช่วงเวลานี้':(isset($lessons[$id])?'ช่วงเวลาทับคาบสอน':null));
        return $out;
    }    public function dutyAvailable(int $teacher,string $date,string $start,string $end,?int $except=null): ?string {
        $term=\App\Support\Saan\TermScope::current();
        if(!$term) return 'ยังไม่ได้ตั้งภาคเรียน';
        if($date<$term->starts_on || $date>$term->ends_on) return 'วันที่อยู่นอกภาคเรียนสาธิต';
        if ($this->query('leave_requests')->where('teacher_id',$teacher)->where('status','approved')->where('starts_on','<=',$date)->where('ends_on','>=',$date)->exists()) return 'ครูมีวันลาที่อนุมัติแล้ว';
        foreach ($this->query('duties')->where('teacher_id',$teacher)->where('date',$date)->where('status','assigned')->when($except,fn($q)=>$q->where('id','!=',$except))->get() as $d) if($this->overlap($start,$end,$d->starts_at,$d->ends_at)) return 'ครูมีเวรทับช่วงเวลานี้';
        $day=CarbonImmutable::parse($date)->isoWeekday();
        if($day<=5) foreach($this->liveLessons()->where('term_id',$term->id)->where('teacher_id',$teacher)->where('weekday',$day)->get() as $l) { $p=$this->record('bell_periods',$l->period_id); if($this->overlap($start,$end,$p->starts_at,$p->ends_at)) return 'ช่วงเวลาทับคาบสอน'; }
        return null;
    }
}
