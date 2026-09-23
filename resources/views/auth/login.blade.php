<!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Connexion GFP</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4">
<form method="POST" action="/login" class="bg-white p-6 rounded-xl border shadow max-w-sm w-full space-y-3">
@csrf
<h1 class="font-extrabold">Connexion GFP</h1>
<input name="matricule" placeholder="Matricule (ex: AGT001)" class="w-full border rounded p-2 text-sm" required>
<input name="password" type="password" placeholder="Mot de passe (test123)" class="w-full border rounded p-2 text-sm" required>
<button class="w-full bg-emerald-700 text-white font-bold py-2 rounded">Se connecter</button>
<p class="text-xs text-slate-500">Comptes démo : AGT001, RH001, SD001, DIR001, DRH001, SEC001, ADM001 / test123</p>
</form>
</body>
</html>
