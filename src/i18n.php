<?php
// ===== Γλώσσες: Ελληνικά / English =====
// Το site είναι γραμμένο στα ελληνικά. Όταν ο επισκέπτης διαλέξει EN, πριν σταλεί η σελίδα
// αλλάζουμε τα ελληνικά κείμενα του site σε αγγλικά, με βάση το λεξικό lang/en.php.
// Ό,τι γράφουν τα μέλη (debates, μηνύματα, τίτλοι...) περνάει από το e() και ΔΕΝ μεταφράζεται.

const I18N_MARK = "\u{2063}";   // αόρατο σημάδι γύρω από κείμενα μελών (σβήνεται πριν σταλεί η σελίδα)

function current_lang()
{
    static $lang = null;
    if ($lang !== null) {
        return $lang;
    }
    $asked = $_GET['lang'] ?? null;
    if ($asked === 'el' || $asked === 'en') {
        $lang = $asked;
        setcookie('lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'secure' => !empty($GLOBALS['is_https']),
                                  'httponly' => true, 'samesite' => 'Lax']);
    } elseif (in_array($_COOKIE['lang'] ?? '', ['el', 'en'], true)) {
        $lang = $_COOKIE['lang'];
    } else {
        // Πρώτη επίσκεψη: η γλώσσα του browser (Έλληνας → ελληνικά, όλοι οι άλλοι → αγγλικά)
        $lang = stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'el', 'el') === 0 ? 'el' : 'en';
    }
    return $lang;
}

function i18n_dict()
{
    static $dict = null;
    if ($dict === null) {
        $dict = current_lang() === 'en' ? (require __DIR__ . '/lang/en.php') : [];
        // Και χωρίς τα σημεία στίξης στις άκρες, π.χ. «). Έτσι, ...» βρίσκεται και ως «Έτσι, ...»
        foreach ($dict as $greek => $english) {
            $bare = preg_replace('/^[\s·,;:.()]+|[\s·,;:.()]+$/u', '', $greek);    // (το trim() χαλάει τα ελληνικά)
            if ($bare !== '' && $bare !== $greek && !isset($dict[$bare])) {
                $dict[$bare] = preg_replace('/^[\s·,;:.()]+|[\s·,;:.()]+$/u', '', $english);
            }
        }
    }
    return $dict;
}

// Κανονικές εκφράσεις για μετάφραση «μέσα σε πρόταση» (μεγαλύτερες φράσεις πρώτα)
function i18n_patterns()
{
    static $chunks = null;
    if ($chunks !== null) {
        return $chunks;
    }
    $keys = array_keys(i18n_dict());
    usort($keys, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
    $chunks = [];
    foreach (array_chunk($keys, 150) as $group) {
        $parts = array_map(function ($key) {
            $body = str_replace(' ', '\\s+', preg_quote($key, '/'));       // κενό = οποιοδήποτε κενό ή αλλαγή γραμμής
            // «Ολόκληρες λέξεις»: αν η φράση αρχίζει/τελειώνει με γράμμα, δεν πρέπει να είναι κομμάτι άλλης λέξης
            $starts = preg_match('/^[\p{L}\p{M}]/u', $key) ? '(?<![\p{L}\p{M}])' : '';
            $ends   = preg_match('/[\p{L}\p{M}]$/u', $key) ? '(?![\p{L}\p{M}])' : '';
            return $starts . $body . $ends;
        }, $group);
        $chunks[] = '/(?:' . implode('|', $parts) . ')/u';
    }
    return $chunks;
}

// Μόνο ολόκληρη φράση (κρατώντας κενά και σημεία στίξης στις άκρες). null αν δεν υπάρχει.
function t_exact($text)
{
    $dict = i18n_dict();
    if (preg_match('/^([\s·,;:.()\x{037E}]*)(.*?)([\s·,;:.()\x{037E}]*)$/su', $text, $m)) {
        foreach ([[$m[1], $m[2], $m[3]], ['', trim($text), '']] as [$before, $core, $after]) {
            $core = preg_replace('/\s+/u', ' ', $core);
            if ($core !== '' && isset($dict[$core])) {
                return $before . $dict[$core] . str_replace([';', "\u{037E}"], '?', $after);   // ελληνικό «;» = «?»
            }
        }
    }
    return null;
}

// Μεταφράζει απλό κείμενο (χωρίς HTML)
function t($text)
{
    if (current_lang() !== 'en' || $text === '' || $text === null) {
        return $text;
    }
    $dict = i18n_dict();
    // 1. Ολόκληρη φράση
    $exact = t_exact($text);
    if ($exact !== null) {
        return $exact;
    }
    // 2. Κομμάτια μέσα στη φράση (π.χ. «Το username «nikos» είναι διαθέσιμο.»)
    if (!preg_match('/[\x{0370}-\x{03FF}\x{1F00}-\x{1FFF}]/u', $text)) {
        return $text;
    }
    foreach (i18n_patterns() as $pattern) {
        $result = preg_replace_callback($pattern, function ($match) use ($dict) {
            return $dict[preg_replace('/\s+/u', ' ', $match[0])] ?? $match[0];
        }, $text);
        if ($result !== null) {        // σε σπάνιο σφάλμα κρατάμε το κείμενο όπως ήταν
            $text = $result;
        }
    }
    return str_replace(["\u{037E}"], '?', $text);
}

// Το e() περνάει από εδώ: ετικέτες του site μεταφράζονται, κείμενα μελών «προστατεύονται»
function i18n_protect($text)
{
    $text = (string) $text;
    if (current_lang() !== 'en') {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    // Ετικέτα του site (ολόκληρη φράση του λεξικού): μεταφράζεται. Αλλιώς είναι κείμενο μέλους.
    // Και στις δύο περιπτώσεις το αποτέλεσμα «προστατεύεται», για να μη μεταφραστεί δεύτερη φορά.
    $exact = t_exact($text);
    return I18N_MARK . htmlspecialchars($exact ?? $text, ENT_QUOTES, 'UTF-8') . I18N_MARK;
}

// Μετάφραση κομματιού HTML κειμένου: τα κείμενα μελών (ανάμεσα στα σημάδια) μένουν ως έχουν
function i18n_fragment($text)
{
    $parts = explode(I18N_MARK, $text);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0 && trim($part) !== '') {
            $parts[$i] = t($part);
        }
    }
    return implode('', $parts);
}

// Όλη η σελίδα, λίγο πριν σταλεί στον browser
function i18n_page($html)
{
    if (current_lang() !== 'en') {
        return str_replace(I18N_MARK, '', $html);
    }
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) {
            return str_replace(I18N_MARK, '', $html);     // JSON, αρχεία κ.λπ.: δεν τα αγγίζουμε
        }
    }
    $dict = i18n_dict();

    // Χωρίζουμε σε: <script>, <style>, <textarea> και το υπόλοιπο HTML
    $pieces = preg_split('/(<script\b[^>]*>.*?<\/script>|<style\b[^>]*>.*?<\/style>|<textarea\b[^>]*>.*?<\/textarea>)/si',
                         $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($pieces as $i => $piece) {
        if ($i % 2 === 1) {
            if (stripos($piece, '<script') === 0) {
                // Μέσα σε JavaScript: μεταφράζουμε μόνο ολόκληρα κείμενα σε 'εισαγωγικά'
                $pieces[$i] = preg_replace_callback("/'((?:[^'\\\\\\n]|\\\\.)*)'/u", function ($m) use ($dict) {
                    $inner = $m[1];
                    if (!preg_match('/[\x{0370}-\x{03FF}]/u', $inner)) {
                        return $m[0];
                    }
                    $translated = t($inner);
                    return $translated === $inner ? $m[0] : "'" . str_replace("'", "\\'", $translated) . "'";
                }, $piece);
            }
            continue;
        }
        // Κείμενο ανάμεσα σε ετικέτες (τα > και < στις άκρες πιάνουν και κείμενο δίπλα σε <textarea>)
        $piece = substr(preg_replace_callback('/>([^<>]+)</u', function ($m) {
            return '>' . i18n_fragment($m[1]) . '<';
        }, '>' . $piece . '<'), 1, -1);
        // Ιδιότητες που βλέπει ο χρήστης
        $piece = preg_replace_callback('/\b(placeholder|title|aria-label|alt|content|value)="([^"]*)"/u', function ($m) {
            if ($m[1] === 'value' && strpos($m[2], I18N_MARK) !== false) {
                return $m[0];                                // τιμές που έγραψε ο χρήστης
            }
            return $m[1] . '="' . i18n_fragment($m[2]) . '"';
        }, $piece);
        $pieces[$i] = $piece;
    }
    $html = implode('', $pieces);
    $html = preg_replace('/<html lang="el">/', '<html lang="en">', $html, 1);
    return str_replace(I18N_MARK, '', $html);
}

// Σύνδεσμος για αλλαγή γλώσσας στην ίδια σελίδα
function lang_switch_url($lang)
{
    $query = $_GET;
    $query['lang'] = $lang;
    return basename($_SERVER['SCRIPT_NAME']) . '?' . http_build_query($query);
}
