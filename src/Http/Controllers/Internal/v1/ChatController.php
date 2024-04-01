<?php

namespace App\Http\Controllers;

use App\Models\ChatChannel;

class ChatController extends Controller
{
    public function get()
    {
        // Retrieve all chat channels
        $channels = ChatChannel::all();
        return response()->json($channels);
    }
    
}