<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Permissions</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="p-8 bg-slate-50"><h1 class="font-bold">Demandes de permission</h1>
<table class="mt-4 w-full text-sm bg-white border"><thead class="bg-slate-100"><tr><th class="p-2 text-left">Dossier</th><th class="p-2 text-left">Agent</th><th class="p-2">Jours</th><th class="p-2">Statut</th></tr></thead>
<tbody>@foreach($demandes as $d)<tr class="border-t"><td class="p-2 font-mono">{{ $d->code_dossier }}</td><td class="p-2">{{ $d->agent->nom ?? '' }}</td><td class="p-2">{{ $d->nombre_jours }}</td><td class="p-2">{{ $d->statut }}</td></tr>@endforeach</tbody></table>
<div class="mt-4">{{ $demandes->links() }}</div></body></html>
