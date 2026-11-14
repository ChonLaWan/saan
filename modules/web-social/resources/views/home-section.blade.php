@php($feed = \Saan\Modules\WebSocial\SocialFeed::forSchool((int) config('school.school_id')))
@if($feed['enabled'] && \Saan\Modules\WebSocial\SocialFeed::validUrl($feed['facebook_url']))
@php(app(\App\Support\PublicCsp::class)->allowFrame(\App\Support\PublicCsp::BLOCK_FRAMES['facebook']))
<section class="school-facebook" id="facebook" aria-labelledby="facebook-heading">
    <div class="section-heading"><div><p class="site-eyebrow">ติดตามโรงเรียน</p><h2 id="facebook-heading">{{ $feed['heading'] }}</h2></div><a class="text-link" href="{{ $feed['facebook_url'] }}" target="_blank" rel="noopener noreferrer">เปิดเพจ Facebook</a></div>
    <div class="facebook-frame" data-facebook-src="{{ \Saan\Modules\WebSocial\SocialFeed::pluginUrl($feed) }}" data-facebook-height="{{ $feed['height'] }}">
        <p>โพสต์จะโหลดจาก Facebook เมื่อกดปุ่ม Facebook อาจบันทึกข้อมูลการเข้าชมตามนโยบายของ Facebook</p>
        <button type="button" class="site-button" data-facebook-load>แสดงโพสต์ล่าสุดจาก Facebook</button>
    </div>
</section>
<style>.school-facebook{max-width:1180px;margin:32px auto;padding:0 16px}.facebook-frame{border:1px solid var(--line,#bac8b4);border-radius:14px;padding:18px;display:grid;justify-items:center;gap:10px;text-align:center;background:var(--surface-raised,#fffdf4)}.facebook-frame iframe{width:100%;max-width:500px;border:0;border-radius:8px}</style>
<script>
document.querySelectorAll('[data-facebook-load]').forEach(b=>b.addEventListener('click',()=>{const box=b.closest('[data-facebook-src]');const f=document.createElement('iframe');f.src=box.dataset.facebookSrc;f.height=box.dataset.facebookHeight;f.title='โพสต์จากเพจ Facebook ของโรงเรียน';f.loading='lazy';f.setAttribute('allow','encrypted-media');f.setAttribute('referrerpolicy','strict-origin-when-cross-origin');box.replaceChildren(f);}));
</script>
@endif
