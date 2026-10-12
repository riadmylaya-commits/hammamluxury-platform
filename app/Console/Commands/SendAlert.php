<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAlert extends Command
{
    protected $signature = 'hl:alert {subject} {body?}';

    protected $description = 'Envoie une alerte technique par e-mail à hl.alert_email (sauvegardes, surveillance)';

    public function handle(): int
    {
        $to = (string) config('hl.alert_email');
        $subject = '['.config('app.env').'] '.$this->argument('subject');
        $body = (string) ($this->argument('body') ?? '');
        if ($body === '' && ! stream_isatty(STDIN)) {
            $body = (string) stream_get_contents(STDIN);
        }

        Mail::raw($body !== '' ? $body : $subject, fn ($m) => $m->to($to)->subject($subject));
        $this->info("Alerte envoyée à {$to}");

        return self::SUCCESS;
    }
}
