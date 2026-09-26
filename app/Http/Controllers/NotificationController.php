<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Liste des notifications de l'agent connecté, avec le nombre de non lues. */
    public function index(Request $request)
    {
        $query = $request->user()->agent->id
            ? Notification::where('agent_id', $request->user()->agent_id)->latest()
            : Notification::latest();

        return response()->json([
            'status' => 'success',
            'data' => $query->paginate(30),
            'unread' => (clone $query)->where('est_lu', false)->count(),
        ]);
    }

    /** Marque toutes les notifications de l'agent connecté comme lues. */
    public function lire(Request $request)
    {
        Notification::where('agent_id', $request->user()->agent_id)->update(['est_lu' => true]);

        return response()->json(['status' => 'success']);
    }
}
