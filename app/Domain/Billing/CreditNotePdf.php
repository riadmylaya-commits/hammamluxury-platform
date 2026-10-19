<?php

namespace App\Domain\Billing;

use App\Models\CreditNote;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * PDF d'un avoir émis : rendu dompdf, disque privé `local`, empreinte SHA-256 enregistrée et revérifiée au téléchargement.
 * Jamais « prêt » sans fichier écrit et relu ; jamais régénéré une fois prêt.
 */
class CreditNotePdf
{
    public const DISK = 'local';

    public function path(CreditNote $note): string
    {
        return 'credit-notes/'.$note->issued_at->format('Y').'/'.$note->number.'.pdf';
    }

    public function render(CreditNote $note): string
    {
        $note->loadMissing(['lines.spa', 'invoice', 'reverses']);

        return Pdf::loadView('pdf.credit-note', ['note' => $note])->setPaper('a4')->output();
    }

    /** @throws CreditNoteException */
    public function generate(CreditNote $note): string
    {
        if (! $note->isIssued()) {
            throw new CreditNoteException('not_issued');
        }
        if ($note->pdf_status === CreditNote::PDF_READY && $this->verify($note)) {
            return $note->pdf_path;
        }
        $bytes = $this->render($note);
        if (! str_starts_with($bytes, '%PDF')) {
            throw new CreditNoteException('pdf_render_failed');
        }
        $path = $this->path($note);
        $disk = Storage::disk(self::DISK);
        if ($disk->put($path, $bytes, ['visibility' => 'private']) !== true
            || ! $disk->fileExists($path)
            || ! hash_equals(hash('sha256', $bytes), $this->sha256($path))) {
            throw new CreditNoteException('pdf_write_failed');
        }

        return $path;
    }

    public function verify(CreditNote $note): bool
    {
        $disk = Storage::disk(self::DISK);
        try {
            if (! $note->pdf_path || ! $note->pdf_sha256 || ! $disk->fileExists($note->pdf_path) || $disk->size($note->pdf_path) === 0) {
                return false;
            }

            return hash_equals($note->pdf_sha256, $this->sha256($note->pdf_path));
        } catch (Throwable) {
            return false;
        }
    }

    public function sha256(string $path): string
    {
        return hash('sha256', (string) Storage::disk(self::DISK)->get($path));
    }
}
