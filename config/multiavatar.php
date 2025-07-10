<?php
function generate_avatar_svg($seed)
{
    $url = "https://api.dicebear.com/7.x/micah/svg?seed=" . urlencode($seed);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0'); // important to bypass API restrictions

    $svg = curl_exec($ch);

    if (curl_errno($ch)) {
        return ''; // fallback in case of failure
    }

    curl_close($ch);
    return $svg;
}
?>
