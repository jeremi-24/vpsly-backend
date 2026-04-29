<x-mail::message>
# Bonjour **{{ $name }}**,

Une nouvelle connexion à votre compte VPSly a été détectée.

<div style="border: 2px solid #000; box-shadow: 4px 4px 0px #000; margin-bottom: 24px; padding: 16px; background-color: #fff;">
<div style="font-weight: 800; font-size: 16px; margin-bottom: 12px; border-bottom: 2px solid #000; padding-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Détails de la connexion</div>
<table style="width: 100%; font-size: 14px; border-collapse: collapse;">
<tr>
<td style="padding: 10px 0; font-weight: 600;">Navigateur</td>
<td style="padding: 10px 0; text-align: right;">{{ $browser }}</td>
</tr>
<tr>
<td style="padding: 10px 0; font-weight: 600; border-top: 1px solid #000;">Système</td>
<td style="padding: 10px 0; text-align: right; border-top: 1px solid #000;">{{ $platform }}</td>
</tr>
<tr>
<td style="padding: 10px 0; font-weight: 600; border-top: 1px solid #000;">Appareil</td>
<td style="padding: 10px 0; text-align: right; border-top: 1px solid #000;">{{ $device }}</td>
</tr>
<tr>
<td style="padding: 10px 0; font-weight: 600; border-top: 1px solid #000;">Adresse IP</td>
<td style="padding: 10px 0; text-align: right; border-top: 1px solid #000;">{{ $ip }}</td>
</tr>
<tr>
<td style="padding: 10px 0; font-weight: 600; border-top: 1px solid #000;">Heure</td>
<td style="padding: 10px 0; text-align: right; border-top: 1px solid #000;">{{ $time }}</td>
</tr>
</table>
</div>

<div style="border: 2px solid #000; background-color: #dcfce7; padding: 14px 16px; margin-bottom: 12px; font-weight: 500; box-shadow: 4px 4px 0px #000;">
<strong>Si c'est vous</strong>, vous pouvez ignorer cet email.
</div>

<div style="border: 2px solid #000; background-color: #fef08a; padding: 14px 16px; margin-bottom: 28px; font-weight: 500; box-shadow: 4px 4px 0px #000;">
<strong>Si vous ne reconnaissez pas cette activité</strong>, nous vous conseillons de changer votre mot de passe immédiatement.
</div>

<x-mail::button :url="$url">
Sécuriser mon compte
</x-mail::button>

<div style="margin-top: 32px; padding-top: 16px; border-top: 2px solid #000; font-weight: 800; font-size: 16px;">
L'équipe de sécurité VPSly<br>
</div>
</x-mail::message>
