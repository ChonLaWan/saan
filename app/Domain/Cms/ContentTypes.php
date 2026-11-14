<?php

namespace App\Domain\Cms;

class ContentTypes
{
    public static function ensureCategories(int $school): void
    {
        foreach(['news'=>'ข่าวประชาสัมพันธ์','activity'=>'กิจกรรมโรงเรียน','admission'=>'รับสมัครนักเรียน','procurement'=>'จัดซื้อจัดจ้าง','jobs'=>'รับสมัครงาน','general'=>'ทั่วไป']as$slug=>$label) {
            \Illuminate\Support\Facades\DB::table('cms_categories')->insertOrIgnore(['school_id'=>$school,'slug'=>$slug,'label'=>$label,'created_at'=>now(),'updated_at'=>now()]);
        }
    }
    public const TYPES = [
        'article'=>['label'=>'ข่าวและกิจกรรม','singular'=>'ข่าว','category'=>'news','route'=>'news'],
        'page'=>['label'=>'หน้าเนื้อหา','singular'=>'หน้าเนื้อหา','category'=>'general','route'=>'pages'],
        'job'=>['label'=>'รับสมัครงาน','singular'=>'ประกาศรับสมัครงาน','category'=>'jobs','route'=>'jobs'],
        'procurement'=>['label'=>'จัดซื้อจัดจ้าง','singular'=>'ประกาศจัดซื้อจัดจ้าง','category'=>'procurement','route'=>'procurement'],
        'album'=>['label'=>'อัลบั้มภาพ','singular'=>'อัลบั้ม','category'=>'activity','route'=>'albums'],
        'download'=>['label'=>'เอกสารดาวน์โหลด','singular'=>'เอกสาร','category'=>'general','route'=>'downloads'],
        'person'=>['label'=>'บุคลากร','singular'=>'ข้อมูลบุคลากร','category'=>'general','route'=>'people'],
        'event'=>['label'=>'ปฏิทินกิจกรรม','singular'=>'กิจกรรม','category'=>'activity','route'=>'events'],
        'banner'=>['label'=>'แบนเนอร์และประกาศด่วน','singular'=>'แบนเนอร์','category'=>'news','route'=>'banners'],
        'service'=>['label'=>'ลิงก์และบริการ','singular'=>'บริการ','category'=>'general','route'=>'services'],
        'video'=>['label'=>'วีดิทัศน์','singular'=>'วีดิทัศน์','category'=>'activity','route'=>'videos'],
        'newsletter'=>['label'=>'จดหมายข่าว','singular'=>'จดหมายข่าว','category'=>'news','route'=>'newsletters'],
    ];

    public static function metadataRules(): array
    {
        return [
            'metadata'=>'present|array:body_format,attachments,gallery,source_kind,source_school,source_url,source_date,checked_on,deadline,opens_on,position,positions,salary,qualification,method,stage,document_no,budget,starts_at,ends_at,location,job_title,department,contact_email,url,placement,seo_title,seo_description,sort_order,demo_key,kicker,button_label,secondary_label,secondary_url,mobile_media_id,focal_x,focal_y,overlay,text_align,tags,video_url,video_media_id,mobile_focal_x,mobile_focal_y,vertical_align,title_size,mobile_title_size,description_size,text_color,overlay_color,mobile_overlay,image_alt,button_style,button_new_tab,secondary_new_tab,hide_kicker,hide_description,issue_no,issued_on,import_source,icon_key',
            'metadata.icon_key'=>'nullable|in:news,activity,admission,downloads,people,calendar,procurement,jobs,contact',
            'metadata.kicker'=>'nullable|string|max:100','metadata.button_label'=>'nullable|string|max:60','metadata.secondary_label'=>'nullable|string|max:60',
            'metadata.secondary_url'=>['nullable','string','max:1000',fn($a,$v,$fail)=>SafeContent::url($v)?:$fail('ลิงก์ปุ่มรองไม่ถูกต้อง')],
            'metadata.mobile_media_id'=>'nullable|integer|min:1','metadata.focal_x'=>'nullable|integer|between:0,100','metadata.focal_y'=>'nullable|integer|between:0,100',
            'metadata.overlay'=>'nullable|numeric|between:0.3,0.85','metadata.text_align'=>'nullable|in:left,center,right',
            'metadata.mobile_focal_x'=>'nullable|integer|between:0,100','metadata.mobile_focal_y'=>'nullable|integer|between:0,100',
            'metadata.vertical_align'=>'nullable|in:top,center,bottom','metadata.title_size'=>'nullable|integer|between:24,64','metadata.mobile_title_size'=>'nullable|integer|between:24,44','metadata.description_size'=>'nullable|integer|between:14,22',
            'metadata.text_color'=>['nullable','regex:/^#[0-9a-fA-F]{6}$/D'],'metadata.overlay_color'=>['nullable','regex:/^#[0-9a-fA-F]{6}$/D'],
            'metadata.mobile_overlay'=>'nullable|numeric|between:0.3,0.85','metadata.image_alt'=>'nullable|string|max:200','metadata.button_style'=>'nullable|in:filled,outline',
            'metadata.button_new_tab'=>'sometimes|boolean','metadata.secondary_new_tab'=>'sometimes|boolean','metadata.hide_kicker'=>'sometimes|boolean','metadata.hide_description'=>'sometimes|boolean',
            'metadata.tags'=>'sometimes|array|max:16','metadata.tags.*'=>'required|string|max:40|distinct',
            'metadata.video_url'=>['nullable','string','max:1000',fn($a,$v,$fail)=>VideoLink::embed($v)?:$fail('ใช้ลิงก์ YouTube หรือ Vimeo แบบ https ที่ถูกต้อง')],
            'metadata.video_media_id'=>'nullable|integer|min:1',
            'metadata.issue_no'=>'nullable|string|max:60','metadata.issued_on'=>'nullable|date_format:Y-m-d','metadata.import_source'=>'nullable|string|max:180',
            'metadata.demo_key'=>'sometimes|string|max:80',
            'metadata.body_format'=>'sometimes|in:html,plain',
            'metadata.attachments'=>'sometimes|array|max:12','metadata.attachments.*'=>'integer|min:1|distinct',
            'metadata.gallery'=>'sometimes|array|max:40','metadata.gallery.*'=>'integer|min:1|distinct',
            'metadata.source_kind'=>'sometimes|in:original,mock,reference',
            'metadata.source_school'=>'nullable|string|max:180','metadata.source_url'=>'nullable|url:https|max:1000',
            'metadata.source_date'=>'nullable|date_format:Y-m-d','metadata.checked_on'=>'nullable|date_format:Y-m-d',
            'metadata.deadline'=>'nullable|date_format:Y-m-d','metadata.opens_on'=>'nullable|date_format:Y-m-d',
            'metadata.position'=>'nullable|string|max:160','metadata.positions'=>'nullable|integer|min:1|max:999',
            'metadata.salary'=>'nullable|string|max:120','metadata.qualification'=>'nullable|string|max:2000',
            'metadata.method'=>'nullable|in:e-bidding,specific,selection,other',
            'metadata.stage'=>'nullable|in:tor,invitation,award,cancelled,contract,information',
            'metadata.document_no'=>'nullable|string|max:100','metadata.budget'=>'nullable|numeric|min:0|max:9999999999',
            'metadata.starts_at'=>'nullable|date','metadata.ends_at'=>'nullable|date|after_or_equal:metadata.starts_at',
            'metadata.location'=>'nullable|string|max:200','metadata.job_title'=>'nullable|string|max:160',
            'metadata.department'=>'nullable|string|max:120','metadata.contact_email'=>'nullable|email|max:254',
            'metadata.url'=>['nullable','string','max:1000',fn($a,$v,$fail)=>SafeContent::url($v)?:$fail('ลิงก์ต้องเป็น https หรือเส้นทางในเว็บไซต์')],
            'metadata.placement'=>'nullable|in:hero,notice,sidebar,popup',
            'metadata.seo_title'=>'nullable|string|max:160','metadata.seo_description'=>'nullable|string|max:300',
            'metadata.sort_order'=>'sometimes|integer|between:0,9999',
        ];
    }
}
