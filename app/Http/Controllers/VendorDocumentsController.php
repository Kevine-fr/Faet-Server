<?php

namespace App\Http\Controllers;

use App\Models\VendorDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class VendorDocumentsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $vendorId = Auth::user()->vendor_id;
        
        $documents = VendorDocument::where('vendor_id', $vendorId)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($documents);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB max
            'document_type' => 'required|string|max:50'
        ]);

        $vendorId = Auth::user()->vendor_id;
        $file = $request->file('file');
        
        // Générer un nom unique pour le fichier
        $fileName = time() . '_' . $file->getClientOriginalName();
        $filePath = 'vendor_documents/' . $vendorId . '/' . $fileName;
        
        // Stocker le fichier
        $path = Storage::disk('public')->put($filePath, $file);
        
        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du stockage du fichier'
            ], 500);
        }

        // Créer l'enregistrement en base
        $document = VendorDocument::create([
            'vendor_id' => $vendorId,
            'document_type' => $request->document_type,
            'document_path' => $filePath,
            'status' => 'pending'
        ]);

        return response()->json([
            'success' => true,
            'document' => $document,
            'message' => 'Document uploadé avec succès'
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(VendorDocument $vendorDocument)
    {
        // Vérifier que le document appartient au vendeur connecté
        if ($vendorDocument->vendor_id !== Auth::user()->vendor_id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        return response()->json($vendorDocument);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(VendorDocument $vendorDocument)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, VendorDocument $vendorDocument)
    {
        // Vérifier que le document appartient au vendeur connecté
        if ($vendorDocument->vendor_id !== Auth::user()->vendor_id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        $request->validate([
            'status' => 'sometimes|in:pending,approved,rejected'
        ]);

        $vendorDocument->update($request->only(['status']));

        return response()->json([
            'success' => true,
            'document' => $vendorDocument,
            'message' => 'Document mis à jour avec succès'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(VendorDocument $vendorDocument)
    {
        // Vérifier que le document appartient au vendeur connecté
        if ($vendorDocument->vendor_id !== Auth::user()->vendor_id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        // Supprimer le fichier physique
        if (Storage::disk('public')->exists($vendorDocument->document_path)) {
            Storage::disk('public')->delete($vendorDocument->document_path);
        }

        // Supprimer l'enregistrement
        $vendorDocument->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document supprimé avec succès'
        ]);
    }

    /**
     * Télécharger un document
     */
    public function download(VendorDocument $vendorDocument)
    {
        // Vérifier que le document appartient au vendeur connecté
        if ($vendorDocument->vendor_id !== Auth::user()->vendor_id) {
            return response()->json(['message' => 'Accès non autorisé'], 403);
        }

        if (!Storage::disk('public')->exists($vendorDocument->document_path)) {
            return response()->json(['message' => 'Fichier non trouvé'], 404);
        }

        return response()->download(Storage::disk('public')->path($vendorDocument->document_path));
    }
}
