<?php

namespace App\Mail;

use App\Models\NoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Note de service envoyée à un destinataire : message d'accompagnement et document Word en pièce jointe. */
class NoteServiceEnvoyee extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly NoteService $note,
        public readonly string $nomDestinataire,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Note de service '.$this->note->numero.' — '.$this->note->objet);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.note-service');
    }

    public function attachments(): array
    {
        return [Attachment::fromStorageDisk('local', $this->note->fichier_path)->as($this->note->fichier_nom)];
    }
}
