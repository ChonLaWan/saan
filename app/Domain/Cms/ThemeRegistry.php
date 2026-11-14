<?php
namespace App\Domain\Cms;

class ThemeRegistry
{
    public static function all(): array
    {
        $themes=config('school-themes',[]);
        foreach($themes as$id=>$theme){
            if(!preg_match('/^[a-z][a-z0-9-]{0,29}$/D',$id)||empty($theme['name'])||!preg_match('/^#[a-f0-9]{6}$/iD',$theme['accent']??'')||!preg_match('/^#[a-f0-9]{6}$/iD',$theme['background']??'')||!in_array($theme['layout']??'', ['cards','list'],true)||!in_array($theme['font']??'',['friendly','formal'],true))throw new \RuntimeException('Invalid school theme package: '.$id);
            foreach(['icons','banner','stylesheet']as$key)if(!empty($theme[$key])&&!preg_match('~^assets/themes/[a-zA-Z0-9_./-]+\.(?:png|webp|jpg|css)$~D',$theme[$key])||(!empty($theme[$key])&&str_contains($theme[$key],'..')))throw new \RuntimeException('Invalid local theme asset');
        }
        return $themes;
    }
    public static function get(string $id): array {return self::all()[$id]??self::all()['atelier'];}
    public static function asset(string $id,string $kind): ?string {$path=self::get($id)[$kind]??null;return $path&&is_file(public_path($path))?\App\Support\WebsiteView::asset($path):null;}
}
