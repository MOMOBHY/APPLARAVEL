<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6;">
  <p>Bonjour {{ $nomDestinataire }},</p>
  <p>
    Veuillez trouver ci-joint la note de service <strong>{{ $note->numero }}</strong>
    du {{ $note->date_note?->format('d/m/Y') }}.
  </p>
  <p style="background-color: #f1f5f9; border-left: 4px solid #047857; padding: 10px 14px;">
    <strong>Objet :</strong> {{ $note->objet }}
  </p>
  <p>
    Elle est également disponible sur la plateforme GFP, dans l'onglet « Notes de service ».
  </p>
  <p style="font-size: 12px; color: #64748b;">
    Ministère de la Fonction Publique et de la Modernisation de l'Administration — message envoyé
    automatiquement, merci de ne pas y répondre.
  </p>
</body>
</html>
