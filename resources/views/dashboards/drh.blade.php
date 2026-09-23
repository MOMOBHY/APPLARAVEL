<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Dashboard drh</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-slate-50 p-8"><h1 class="font-extrabold text-xl">Espace drh</h1>
<p class="text-sm text-slate-600">Connecté : {{ auth()->user()->name }} ({{ auth()->user()->matricule }})</p>
<div class="mt-4 flex gap-2 text-sm"><a href="/permissions" class="px-3 py-2 bg-white border rounded font-bold">Permissions</a><a href="/notes" class="px-3 py-2 bg-white border rounded font-bold">Notes</a><a href="/" class="px-3 py-2 bg-white border rounded">Accueil</a></div>
<p class="mt-4 text-xs text-slate-500">API : /api/permissions, /api/naissances, /api/deces, /api/notes, /api/notifications</p>
</body></html>
