<?php

namespace Tests\Feature;

use App\Domain\Cms\ContentTypes;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_g3_staff_directory_groups_by_department_in_sort_order_and_hides_empty_email(): void
    {
        config(['school.demo'=>true]);$this->seed();$this->actingAs(User::where('role','admin')->first());
        $add=fn($title,$dept,$sort,$email='')=>$this->postJson('/api/site/content',['title'=>$title,'body'=>'x','excerpt'=>'x','content_type'=>'person','category'=>ContentTypes::TYPES['person']['category'],'is_pinned'=>false,'metadata'=>['source_kind'=>'original','body_format'=>'html','job_title'=>'ครู','department'=>$dept,'sort_order'=>$sort,'contact_email'=>$email],'intent'=>'publish'])->assertCreated()->json();
        $add('DIR-TEACHER-A','กลุ่มสาระคณิตศาสตร์',20);$add('DIR-HEAD','ZZ-ผู้บริหาร',1);$p=$add('DIR-NO-EMAIL','กลุ่มสาระคณิตศาสตร์',21);
        $this->app['auth']->forgetGuards();
        $html=$this->get('/portal/people')->assertOk()->assertSee('class="people-groups"',false)->getContent();
        $this->assertLessThan(strpos($html,'<h2>กลุ่มสาระคณิตศาสตร์</h2>'),strpos($html,'<h2>ZZ-ผู้บริหาร</h2>'),'group with the smallest sort order comes first');
        $this->get('/portal/people/'.$p['slug'])->assertOk()->assertDontSee('อีเมลติดต่อ');
    }
}
