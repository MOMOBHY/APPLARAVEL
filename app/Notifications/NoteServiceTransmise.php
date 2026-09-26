<?php

namespace App\Notifications;

use App\Models\NoteService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Transmission par email de la note saisie par le secrétariat à ses destinataires. */
class NoteServiceTransmise extends Notification
{
    use Queueable;

    public function __construct(public NoteService $note) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Construit l'email envoyé aux destinataires lors de la diffusion d'une note de service. */
    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject("Note de service {$this->note->numero_reference}")
            ->greeting('Bonjour,')
            ->line("Vous êtes destinataire de la note de service {$this->note->numero_reference}.")
            ->line("Objet : {$this->note->objet}");

        if (filled($this->note->contenu)) {
            $message->line($this->note->contenu);
        }

        return $message
            ->line('Émise par : '.($this->note->signataire?->fullName() ?? 'la Direction'))
            ->action('Consulter sur la plateforme GFP', url('/'));
    }
}
