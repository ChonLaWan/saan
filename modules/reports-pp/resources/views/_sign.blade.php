@if($d['template']['options']['footer_note'] !== '')<p class="note">{{ $d['template']['options']['footer_note'] }}</p>@endif
<div class="sign">@foreach($d['signers'] as $s)<div><div class="line"></div>({{ $s['name'] !== '' ? $s['name'] : '........................................' }})<br>{{ $s['label'] }}</div>@endforeach</div>
