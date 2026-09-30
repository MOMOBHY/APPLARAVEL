<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Mot de passe oublié : envoie à l'agent son code de réinitialisation avec le lien direct vers la page
 * où choisir son nouveau mot de passe. */
class CodeReinitialisation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $nomAgent,
        public readonly string $code,
        public readonly string $lien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réinitialisation de votre mot de passe — Plateforme GFP',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.code-reinitialisation',
        );
    }
}
