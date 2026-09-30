<!DOCTYPE html>
<html lang="fr">
<body style="font-family: Arial, sans-serif; color: #1e293b; line-height: 1.6;">
  <p>Bonjour {{ $nomAgent }},</p>
  <p>
    Vous avez demandé la réinitialisation de votre mot de passe sur la plateforme GFP
    (Ministère de la Fonction Publique et de la Modernisation de l'Administration).
  </p>
  <p>
    Votre code de réinitialisation : <strong style="font-size: 18px;">{{ $code }}</strong>
  </p>
  <p>
    <a href="{{ $lien }}" style="display: inline-block; background-color: #047857; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 8px; font-weight: bold;">Choisir mon nouveau mot de passe</a>
  </p>
  <p style="font-size: 12px; color: #64748b;">
    Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :<br>
    {{ $lien }}
  </p>
  <p style="font-size: 12px; color: #64748b;">
    Ce code est à usage unique et valable {{ \App\Http\Controllers\MotDePasseController::VALIDITE_MINUTES }} minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.
  </p>
</body>
</html>
