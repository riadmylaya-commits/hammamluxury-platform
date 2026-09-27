<?php

namespace App\Domain\Privacy;

/** Masque e-mails, téléphones, URLs et pseudos dans un texte libre (descriptions, messages) avant confirmation. */
class ContactMasker
{
    private const PATTERNS = [
        '/[\w.+-]+\s*(?:@|\(at\)|\[at\]|\sat\s)\s*[\w-]+(?:\s*(?:\.|\(dot\)|\[dot\]|\sdot\s)\s*[\w-]+)+/iu',
        '/(?:https?:\/\/|www\.)[^\s]+/iu',
        '/\b[\w-]+\.(?:com|net|org|ma|fr|io|co|info|biz|shop|site|online)(?:\/[^\s]*)?\b/iu',
        '/(?:\+|00)?\s*\d(?:[\s.\-()]*\d){7,14}/u',
        '/(?:wa\.me|whatsapp|t\.me|telegram|snap(?:chat)?|insta(?:gram)?|facebook|fb\.com|tiktok)\s*[:\/]?\s*@?[\w.\-\/]+/iu',
        '/(?<![\w.])@[\w.]{3,}/u',
    ];

    public static function redact(string $text, ?string $mask = null): string
    {
        return (string) preg_replace(self::PATTERNS, $mask ?? __('booking.contact_masked'), $text);
    }

    public static function containsContact(string $text): bool
    {
        foreach (self::PATTERNS as $p) {
            if (preg_match($p, $text)) {
                return true;
            }
        }

        return false;
    }
}
