<?php
namespace App\Domain;
use Illuminate\Validation\ValidationException;
class ScoreCalculator {
    public function centi(string $value, int $maximum): int {
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value)) throw ValidationException::withMessages(['score'=>'กรอกคะแนนไม่เกินสองตำแหน่ง และห้ามแทนค่าว่างด้วยศูนย์']);
        $parts = explode('.', $value);
        $result = ((int)$parts[0])*100 + (int)str_pad($parts[1] ?? '', 2, '0');
        if ($result > $maximum*100) throw ValidationException::withMessages(['score'=>'คะแนนเกินน้ำหนักที่กำหนด']);
        return $result;
    }
    public function preview(string $continuous, string $exam): array {
        $a=$this->centi($continuous,70); $b=$this->centi($exam,30);
        $rounded=intdiv($a+$b+50,100);
        $grade = match(true) {$rounded>=80=>'4',$rounded>=75=>'3.5',$rounded>=70=>'3',$rounded>=65=>'2.5',$rounded>=60=>'2',$rounded>=55=>'1.5',$rounded>=50=>'1',default=>'0'};
        return ['continuous_centi'=>$a,'exam_centi'=>$b,'raw_total'=>number_format(($a+$b)/100,2,'.',''),'rounded_total'=>$rounded,'grade'=>$grade,'policy_version'=>'DEMO-70-30-HALF-UP-v1'];
    }
}
