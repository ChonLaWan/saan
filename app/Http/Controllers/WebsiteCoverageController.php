<?php
namespace App\Http\Controllers;

use App\Domain\Cms\WebsiteContent;
use App\Support\WebsiteCoverage;
use App\Support\WebsiteSections;

class WebsiteCoverageController extends Controller
{
    public function __invoke(WebsiteContent $content)
    {
        $content->authorize();
        return view('website-coverage',['sections'=>WebsiteSections::all(),'controls'=>WebsiteCoverage::controls(),
            'navigation'=>WebsiteCoverage::navigation(),'modules'=>WebsiteCoverage::modules()]);
    }
}
