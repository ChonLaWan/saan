<?php
namespace App\Http\Controllers;
use App\Domain\SchoolOperations;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SchoolController extends Controller {
    public function __construct(private SchoolOperations $ops) {}
    public function loginPage() { return view('login'); }
    public function login(Request $r) {
        $d=$r->validate(['role'=>['required',Rule::in(['admin','teacher','principal'])]]);
        $user=User::where('school_id',config('school.school_id'))->where('role',$d['role'])->where('is_demo',true)->where('is_active',true)->firstOrFail();
        Auth::login($user); $r->session()->regenerate(); $r->session()->put('auth_version',$user->auth_version); return redirect('/');
    }
    public function logout(Request $r) { Auth::logout();$r->session()->invalidate();$r->session()->regenerateToken();return redirect('/login'); }
    public function index() {
        $role=auth()->user()->role;
        if(in_array($role,['admin','principal','editor'],true)) return redirect('/cms');
        if(app(\App\Support\Modules\ModuleRegistry::class)->workMenu($role)) return redirect('/work');
        return $this->operations();
    }
    public function operations() { $this->ops->role(['admin','principal','teacher']); $modules=app(\App\Support\Modules\ModuleRegistry::class); if(!$modules->active('operations')) return response()->view('module-unavailable',['module'=>$modules->get('operations')]); return view('atelier',['boot'=>['user'=>['name'=>auth()->user()->name,'role'=>auth()->user()->role,'classroom_id'=>auth()->user()->classroom_id,'teacher_id'=>auth()->user()->teacher_id],'base'=>url('/'),'today'=>now()->toDateString()]]); }
    public function catalogue() {$this->ops->role(['admin','principal','teacher']);return response()->json(json_decode(file_get_contents(resource_path('data/module-catalogue.json')),true,512,JSON_THROW_ON_ERROR));}
        /**
     * OPS-010: read-only overview of the CURRENT term. Bounded payload: rosters are limited to ONE classroom
     * (?classroom_id=, default first visible), every list has a hard cap and whole-school figures are counts only.
     */
    public function state(Request $r) {
        $this->ops->role(['admin','principal','teacher']);
        $user=auth()->user();$teacher=$user->role==='teacher';
        $term=\App\Support\Saan\TermScope::currentId();
        $today=now()->toDateString();
        $cap=['classrooms'=>200,'teachers'=>500,'periods'=>20,'lessons'=>2500,'students'=>200,'duties'=>150,'leaves'=>100,'attendances'=>200,'grades'=>400,'posts'=>8,'audit'=>8];
        $classes=$this->ops->query('classrooms')->when($teacher,fn($q)=>$q->where('id',$user->classroom_id))->orderBy('id')->limit($cap['classrooms'])->get();
        $ids=$classes->pluck('id');
        $want=(int)$r->query('classroom_id');
        $selected=$ids->contains($want)?$want:($ids->first()?:null);
        $studying=fn($q)=>$q->when(\Illuminate\Support\Facades\Schema::hasColumn('students','status'),fn($q)=>$q->where('status','studying'));
        $students=$selected?$studying($this->ops->query('students'))->where('classroom_id',$selected)->orderBy('id')->limit($cap['students'])->get(['id','classroom_id','code','name']):collect();
        $sid=$students->pluck('id');
        // another person's unsent draft (its reason can be a medical one) is not for the admin or principal; attachment_path is never returned (the leave module serves files itself)
        $leaveBase=fn()=>$this->ops->query('leave_requests')->when($teacher,fn($q)=>$q->where('teacher_id',$user->teacher_id),fn($q)=>$q->where(fn($w)=>$w->where('status','!=','draft')->when($user->teacher_id,fn($w)=>$w->orWhere('teacher_id',$user->teacher_id))));
        $termRow=$term?$this->ops->record('academic_terms',$term):null;
        $counts=[
            'teachers'=>$this->ops->query('teachers')->count(),
            'classrooms'=>$this->ops->query('classrooms')->when($teacher,fn($q)=>$q->where('id',$user->classroom_id))->count(),
            'students'=>$studying($this->ops->query('students'))->when($teacher,fn($q)=>$q->whereIn('classroom_id',$ids))->count(),
            'lessons'=>$term?$this->ops->liveLessons()->where('term_id',$term)->count():0,
            'duties_upcoming'=>$this->ops->query('duties')->where('date','>=',$today)->count(),
            'leaves_pending'=>$leaveBase()->whereIn('status',['submitted','pending'])->count(),
            'attendance_today'=>$this->ops->query('attendances')->where('date',$today)
                ->when($teacher,fn($q)=>$q->whereIn('student_id',$this->ops->query('students')->whereIn('classroom_id',$ids)->select('id')))
                ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n','status'),
            'grades_term'=>$term?$this->ops->query('grade_entries')->where('term_id',$term)
                ->when($teacher,fn($q)=>$q->whereIn('student_id',$this->ops->query('students')->whereIn('classroom_id',$ids)->select('id')))->count():0,
        ];
        return response()->json([
            'teachers'=>$this->ops->query('teachers')->orderBy('id')->limit($cap['teachers'])->get(),'classrooms'=>$classes,'students'=>$students,
            'periods'=>$this->ops->query('bell_periods')->orderBy('starts_at')->orderBy('number')->limit($cap['periods'])->get(),
            'lessons'=>$term?$this->ops->liveLessons()->where('term_id',$term)->orderBy('id')->limit($cap['lessons'])->get():[],
            'duties'=>$this->ops->query('duties')->when($termRow,fn($q)=>$q->whereBetween('date',[$termRow->starts_on,$termRow->ends_on]))->orderByDesc('date')->orderByDesc('id')->limit($cap['duties'])->get(),
            'leaves'=>$leaveBase()->when($termRow,fn($q)=>$q->where('ends_on','>=',$termRow->starts_on)->where('starts_on','<=',$termRow->ends_on))->orderByDesc('id')->limit($cap['leaves'])->get(['id','teacher_id','starts_on','ends_on','type','reason','status','decided_at','updated_at']),
            'attendances'=>$sid->isEmpty()?[]:$this->ops->query('attendances')->whereIn('student_id',$sid)->when($termRow,fn($q)=>$q->whereBetween('date',[$termRow->starts_on,$termRow->ends_on]))->orderByDesc('date')->limit($cap['attendances'])->get(),
            'grades'=>($sid->isEmpty()||!$term)?[]:$this->ops->query('grade_entries')->where('term_id',$term)->whereIn('student_id',$sid)->limit($cap['grades'])->get(),
            'posts'=>$this->ops->query('posts')->whereNull('deleted_at')->when($teacher,fn($q)=>$q->where('status','published'))->orderByDesc('id')->limit($cap['posts'])->get(['id','title','body','excerpt','category','cover_media_id','status','published_at','updated_at','version'])->map(function($p){$p->body=\Illuminate\Support\Str::limit(strip_tags((string)$p->body),240);return $p;}),
            'audit'=>\App\Support\Saan\AuditFeed::visible($this->ops->query('audit_events'))->when($teacher,fn($q)=>$q->where('actor_id',auth()->id()))->orderByDesc('id')->limit($cap['audit'])->get(),
            'term'=>$termRow,
            'counts'=>$counts,'selected_classroom_id'=>$selected,'limits'=>$cap,
        ]);
    }

    /** Retired prototype write path (N8 cutover): 410 Gone, pointing at the module page that replaced it. */
    public function moved(Request $r) {
        $page=(string)$r->route('page');
        return response()->json(['message'=>'ย้ายไปที่ /work#'.$page,'moved_to'=>'/work#'.$page],410);
    }
    public function suggestions(Request $r) {
        $this->ops->role(['admin']);
        $d=$r->validate(['date'=>'required|date_format:Y-m-d','starts_at'=>'required|date_format:H:i','ends_at'=>'required|date_format:H:i|after:starts_at']);
        $start=\Carbon\CarbonImmutable::parse($d['date'])->startOfWeek()->toDateString();$end=\Carbon\CarbonImmutable::parse($start)->addDays(6)->toDateString();
        // CORE-PERF: five bulk queries for every teacher instead of ~10-15 queries per teacher
        $all=$this->ops->query('teachers')->get(['id','name']);$unavailable=$this->ops->dutyAvailableMany($all->pluck('id')->all(),$d['date'],$d['starts_at'],$d['ends_at']);
        $weekly=$this->ops->query('duties')->whereIn('teacher_id',$all->pluck('id')->all())->whereBetween('date',[$start,$end])->where('status','assigned')->get(['teacher_id','starts_at','ends_at'])->groupBy('teacher_id');
        $teachers=$all->map(function($t) use($unavailable,$weekly) {
            $mins=0;foreach($weekly->get($t->id,[]) as $x) $mins+=\Carbon\CarbonImmutable::parse($x->starts_at)->diffInMinutes(\Carbon\CarbonImmutable::parse($x->ends_at));
            return ['teacher_id'=>$t->id,'name'=>$t->name,'minutes'=>$mins,'unavailable'=>$unavailable[$t->id]??null];
        })->sortBy([fn($a,$b)=>(($a['unavailable']!==null)<=>($b['unavailable']!==null)),fn($a,$b)=>$a['minutes']<=>$b['minutes'],fn($a,$b)=>$a['teacher_id']<=>$b['teacher_id']])->values();
        return response()->json(['candidates'=>$teachers,'method'=>'available first, weekly assigned minutes ascending; advisory only']);
    }
    public function post(Request $r) {
        $this->ops->role(['admin']);$d=$r->validate(['title'=>'required|string|max:180','body'=>'required|string|max:10000']);
        $saved=app(\App\Domain\CmsPublishing::class)->savePost($d,null,0,false);return response()->json(['id'=>$saved['id']],201);
    }
    public function publish(int $id) {
        $this->ops->role(['admin','principal']);$cms=app(\App\Domain\CmsPublishing::class);$post=$this->ops->record('posts',$id);abort_unless($post->status==='draft',409);$draft=$cms->post($id)['draft'];$cms->savePost($draft,$id,$post->version,true);return response()->json(['ok'=>true]);
    }
}
