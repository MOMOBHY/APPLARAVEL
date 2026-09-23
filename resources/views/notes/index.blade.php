<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><title>Notes</title><script src="https://cdn.tailwindcss.com"></script></head>
<body class="p-8 bg-slate-50"><h1 class="font-bold">Notes de service</h1>
@foreach($notes as $n)<div class="bg-white border rounded p-3 mt-2"><b>{{ $n->numero_reference }}</b> — {{ $n->objet }} <span class="text-xs">({{ $n->statut }})</span></div>@endforeach
<div class="mt-4">{{ $notes->links() }}</div></body></html>
