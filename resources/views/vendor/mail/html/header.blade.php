@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'VPSly' || trim($slot) === 'Laravel')
<img src="{{ config('app.mail_logo_url') }}" alt="VPSly Logo" style="height: 45px; width: auto; border-radius: 6px;">
@else
{!! $slot !!}
@endif
</a>
</td>
</tr>
