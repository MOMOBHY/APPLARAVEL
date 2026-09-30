<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Demandes de permission</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="p-8 bg-slate-50">
<h1 class="font-bold text-lg">MODULE 1 — Demandes de permission</h1>
<p class="text-xs text-slate-500">Dashboard séparé des naissances. Statuts : BROUILLON, EN_ATTENTE_RH, RETOUR_CORRECTION, EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR, EN_ATTENTE_VALIDATION_DIRECTEUR, EN_ATTENTE_DRH, VALIDEE, REJETEE.</p>
<form method="GET" class="mt-4 flex flex-wrap gap-2 text-sm bg-white border rounded p-3">
<input name="search" value="{{ request('search') }}" placeholder="Recherche (dossier, motif, matricule)" class="border rounded px-2 py-1">
<select name="statut" class="border rounded px-2 py-1"><option value="">Tous statuts</option>@foreach(['BROUILLON','EN_ATTENTE_RH','RETOUR_CORRECTION','EN_ATTENTE_VALIDATION_SOUS_DIRECTEUR','EN_ATTENTE_VALIDATION_DIRECTEUR','EN_ATTENTE_DRH','VALIDEE','REJETEE'] as $s)<option value="{{ $s }}" @selected(request('statut')===$s)>{{ $s }}</option>@endforeach</select>
<input type="date" name="date" value="{{ request('date') }}" class="border rounded px-2 py-1" title="Date">
<input type="date" name="date_debut" value="{{ request('date_debut') }}" class="border rounded px-2 py-1" title="Période début">
<input type="date" name="date_fin" value="{{ request('date_fin') }}" class="border rounded px-2 py-1" title="Période fin">
<input name="type_permission_id" value="{{ request('type_permission_id') }}" placeholder="Type ID" class="border rounded px-2 py-1 w-24">
<input name="structure_id" value="{{ request('structure_id') }}" placeholder="Structure ID" class="border rounded px-2 py-1 w-28">
<button class="px-3 py-1 bg-emerald-700 text-white rounded font-bold">Filtrer</button>
</form>
<table class="mt-4 w-full text-sm bg-white border"><thead class="bg-slate-100"><tr><th class="p-2 text-left">Dossier</th><th class="p-2 text-left">Agent</th><th class="p-2">Jours</th><th class="p-2">Statut</th></tr></thead>
<tbody>@foreach($demandes as $d)<tr class="border-t"><td class="p-2 font-mono">{{ $d->code_dossier }}</td><td class="p-2">{{ $d->agent->nom ?? '' }}</td><td class="p-2">{{ $d->nombre_jours }}</td><td class="p-2">{{ $d->statut }}</td></tr>@endforeach</tbody></table>
<div class="mt-4">{{ $demandes->appends(request()->query())->links() }}</div></body></html>
