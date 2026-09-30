<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Déclarations de naissance</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="p-8 bg-slate-50">
<h1 class="font-bold text-lg">MODULE 2 — Déclarations de naissance</h1>
<p class="text-xs text-slate-500">Dashboard séparé des permissions. Circuit : Agent → Gestionnaire RH → Responsable → DRH → Agent. Statuts : BROUILLON, EN_ATTENTE_RH, RETOUR_CORRECTION, EN_ATTENTE_VALIDATION_RESPONSABLE, EN_ATTENTE_DRH, VALIDEE, REJETEE.</p>
<div class="mt-3"><button class="px-3 py-2 bg-emerald-700 text-white rounded text-sm font-bold">+ Nouvelle déclaration de naissance</button></div>
<form method="GET" class="mt-4 flex flex-wrap gap-2 text-sm bg-white border rounded p-3">
<input name="search" value="{{ request('search') }}" placeholder="Recherche (dossier, enfant, matricule)" class="border rounded px-2 py-1">
<select name="statut" class="border rounded px-2 py-1"><option value="">Tous statuts</option>@foreach(['BROUILLON','EN_ATTENTE_RH','RETOUR_CORRECTION','EN_ATTENTE_VALIDATION_RESPONSABLE','EN_ATTENTE_DRH','VALIDEE','REJETEE'] as $s)<option value="{{ $s }}" @selected(request('statut')===$s)>{{ $s }}</option>@endforeach</select>
<input type="date" name="date" value="{{ request('date') }}" class="border rounded px-2 py-1" title="Date de déclaration">
<input type="date" name="date_debut" value="{{ request('date_debut') }}" class="border rounded px-2 py-1" title="Période début">
<input type="date" name="date_fin" value="{{ request('date_fin') }}" class="border rounded px-2 py-1" title="Période fin">
<input name="structure_id" value="{{ request('structure_id') }}" placeholder="Structure ID" class="border rounded px-2 py-1 w-28">
<input name="agent_id" value="{{ request('agent_id') }}" placeholder="Agent ID" class="border rounded px-2 py-1 w-24">
<button class="px-3 py-1 bg-emerald-700 text-white rounded font-bold">Filtrer</button>
</form>
<table class="mt-4 w-full text-sm bg-white border"><thead class="bg-slate-100"><tr><th class="p-2 text-left">Dossier</th><th class="p-2 text-left">Enfant</th><th class="p-2 text-left">Agent</th><th class="p-2">Statut</th></tr></thead>
<tbody>@foreach($naissances as $n)<tr class="border-t"><td class="p-2 font-mono">{{ $n->code_dossier }}</td><td class="p-2">{{ $n->nom_enfant }} {{ $n->prenom_enfant }}</td><td class="p-2">{{ $n->agent->nom ?? '' }}</td><td class="p-2">{{ $n->statut }}</td></tr>@endforeach</tbody></table>
<div class="mt-4">{{ $naissances->appends(request()->query())->links() }}</div></body></html>
