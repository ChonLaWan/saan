<?php
namespace App\Domain\Cms;
use App\Domain\SchoolOperations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageVariant
{
    public function __construct(private WebsiteContent $content,private SchoolOperations $ops){}
    public function create(int $id,array $options): array
    {
        $this->content->authorize();$source=$this->ops->record('cms_media',$id);abort_unless(in_array($source->mime,['image/jpeg','image/png','image/webp'],true),422,'เลือกไฟล์ภาพที่รองรับ');abort_if($source->width*$source->height>16000000,422,'ภาพต้นฉบับใหญ่เกินพื้นที่ประมวลผล กรุณาใช้ภาพไม่เกิน 16 ล้านจุด');
        \App\Support\StorageQuota::assertRoom($this->ops->school(),1); // already full: refuse before spending CPU and memory on encoding
        $disk=Storage::disk('local');abort_unless($disk->exists($source->path),404);
        abort_unless($source->width>0&&$source->height>0,422,'อ่านขนาดภาพไม่ได้');
        $requestedRatio=$options['ratio']==='original'?$source->width/$source->height:(float)explode(':',$options['ratio'])[0]/(float)explode(':',$options['ratio'])[1];
        $estimate=$source->width*$source->height*12+(int)$options['width']*((int)$options['width']/$requestedRatio)*6+$source->bytes*2+8388608;
        $limit=ini_parse_quantity(ini_get('memory_limit'));abort_if($limit>0&&memory_get_usage(true)+$estimate>$limit,422,'พื้นที่ประมวลผลภาพไม่พอ กรุณาใช้ภาพหรือความกว้างที่เล็กลง');
        $image=@imagecreatefromstring($disk->get($source->path));abort_unless($image,422,'อ่านภาพไม่ได้');
        if($source->mime==='image/jpeg'&&function_exists('exif_read_data')){
            $orientation=(int)(@exif_read_data($disk->path($source->path))['Orientation']??1);
            if(in_array($orientation,[3,6,8,5,7],true)){$rotated=imagerotate($image,match($orientation){3=>180,6=>-90,default=>90},0);imagedestroy($image);$image=$rotated;}
            if(in_array($orientation,[2,7],true))imageflip($image,IMG_FLIP_HORIZONTAL);if(in_array($orientation,[4,5],true))imageflip($image,IMG_FLIP_VERTICAL);
        }
        $sw=imagesx($image);$sh=imagesy($image);$ratio=$options['ratio']==='original'?$sw/$sh:(float)explode(':',$options['ratio'])[0]/(float)explode(':',$options['ratio'])[1];
        $cropW=min($sw,$sh*$ratio);$cropH=$cropW/$ratio;$x=($sw-$cropW)*($options['focal_x']/100);$y=($sh-$cropH)*($options['focal_y']/100);$width=min((int)$options['width'],(int)round($cropW));$height=max(1,(int)round($width/$ratio));
        $target=imagecreatetruecolor($width,$height);imagealphablending($target,false);imagesavealpha($target,true);imagefilledrectangle($target,0,0,$width,$height,$options['format']==='jpeg'?imagecolorallocate($target,255,255,255):imagecolorallocatealpha($target,0,0,0,127));if($options['format']==='jpeg')imagealphablending($target,true);imagecopyresampled($target,$image,0,0,(int)round($x),(int)round($y),$width,$height,(int)round($cropW),(int)round($cropH));imagedestroy($image);
        $format=$options['format'];$extension=$format==='jpeg'?'jpg':$format;$path='cms/'.$this->ops->school().'/'.Str::uuid().'.'.$extension;$disk->makeDirectory('cms/'.$this->ops->school());$absolute=$disk->path($path);
        try{$written=match($format){'webp'=>imagewebp($target,$absolute,$options['quality']),'jpeg'=>imagejpeg($target,$absolute,$options['quality']),'png'=>imagepng($target,$absolute,7)};imagedestroy($target);abort_unless($written,500,'สร้างภาพไม่สำเร็จ');
            \App\Support\StorageQuota::assertRoom($this->ops->school(),(int)@filesize($absolute)); // real size of the encoded file; the catch below deletes it when over quota
            $newId=DB::transaction(function()use($id,$source,$path,$format,$width,$height,$disk){$this->ops->query('cms_media')->where('id',$id)->lockForUpdate()->first()??abort(404);$newId=DB::table('cms_media')->insertGetId(['school_id'=>$this->ops->school(),'uploaded_by'=>auth()->id(),'source_media_id'=>$id,'path'=>$path,'mime'=>'image/'.$format,'bytes'=>$disk->size($path),'width'=>$width,'height'=>$height,'alt'=>$source->alt,'original_name'=>mb_substr(pathinfo($source->original_name?:'image',PATHINFO_FILENAME),0,150).'-'.$width.'.'.($format==='jpeg'?'jpg':$format),'folder'=>$source->folder,'created_at'=>now(),'updated_at'=>now()]);$this->ops->audit('media.variant_created','cms_media',$newId,['source_media_id'=>$id,'width'=>$width,'height'=>$height]);return$newId;});
        }catch(\Throwable$e){$disk->delete($path);throw$e;}
        return ['id'=>$newId,'source_media_id'=>$id,'mime'=>'image/'.$format,'width'=>$width,'height'=>$height,'bytes'=>$disk->size($path),'url'=>url('/media/'.$newId)];
    }
}
