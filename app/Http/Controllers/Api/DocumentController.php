<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Supporting documents on the private disk, verified by admins. */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Document::with(['uploader:id,name', 'verifier:id,name', 'documentable'])
            ->when($request->category, fn ($q, $v) => $q->where('category', $v))
            ->when($request->verification_status, fn ($q, $v) => $q->where('verification_status', $v))
            ->when($request->owner_type && $request->owner_id, fn ($q) => $q
                ->where('documentable_type', Document::OWNERS[$request->owner_type] ?? '-')->where('documentable_id', $request->owner_id))
            ->when($request->q, fn ($q, $v) => $q->where('name', 'like', "%{$v}%"))
            ->latest();

        // Customers see only their own documents and the ones on their deals.
        if ($this->isCustomer()) {
            $customer = $this->currentCustomer();
            $query->where(fn ($q) => $q->where('uploaded_by', auth()->id())
                ->orWhere(fn ($w) => $w->where('documentable_type', Customer::class)->where('documentable_id', $customer?->id ?? 0)));
        } elseif (! $this->isAdmin()) {
            $query->where(fn ($q) => $q->where('uploaded_by', auth()->id())->orWhere('is_private', false)
                ->orWhereIn('documentable_type', [\App\Models\Property::class, \App\Models\Sale::class]));
        }

        return response()->json($query->paginate($this->perPage()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|max:'.config('rems.uploads.max_document_kb').'|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx',
            'name' => 'nullable|string|max:190',
            'category' => ['required', Rule::in(Document::CATEGORIES)],
            'owner_type' => ['nullable', Rule::in(array_keys(Document::OWNERS))],
            'owner_id' => 'nullable|integer|required_with:owner_type',
            'expires_at' => 'nullable|date',
            'is_private' => 'sometimes|boolean',
        ]);

        $ownerClass = isset($data['owner_type']) ? Document::OWNERS[$data['owner_type']] : null;
        if ($ownerClass) {
            abort_unless($ownerClass::whereKey($data['owner_id'])->exists(), 422, 'The record this document belongs to was not found.');
        }
        if ($this->isCustomer()) {
            $ownerClass = Customer::class;
            $data['owner_id'] = $this->currentCustomer()?->id;
        }

        $file = $request->file('file');
        $document = Document::create([
            'documentable_type' => $ownerClass,
            'documentable_id' => $ownerClass ? $data['owner_id'] : null,
            'uploaded_by' => auth()->id(),
            'name' => $data['name'] ?? $file->getClientOriginalName(),
            'category' => $data['category'],
            'path' => $file->store('documents/'.tenant()->id(), 'local'),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'expires_at' => $data['expires_at'] ?? null,
            'is_private' => $data['is_private'] ?? true,
        ]);

        NotificationService::toAdmins('verification.requested', "Document to verify: {$document->name}", str_replace('_', ' ', $document->category), '/admin/verifications');

        return response()->json(['data' => $document->load('uploader:id,name')], 201);
    }

    public function download(Document $document): StreamedResponse
    {
        if ($this->isCustomer()) {
            $customer = $this->currentCustomer();
            abort_unless($document->uploaded_by === auth()->id()
                || ($document->documentable_type === Customer::class && $document->documentable_id === $customer?->id), 404);
        }
        abort_unless(Storage::disk('local')->exists($document->path), 404, 'File missing.');

        return Storage::disk('local')->download($document->path, $document->name.(pathinfo($document->name, PATHINFO_EXTENSION) ? '' : '.'.pathinfo($document->path, PATHINFO_EXTENSION)));
    }

    public function destroy(Document $document): JsonResponse
    {
        abort_unless($this->isAdmin() || $document->uploaded_by === auth()->id(), 403);
        abort_if($document->verification_status === 'verified' && ! $this->isAdmin(), 422, 'Verified documents can only be removed by an admin.');
        Storage::disk('local')->delete($document->path);
        $document->delete();
        AuditLogger::log('document.deleted', $document, "Deleted document {$document->name}");

        return response()->json(['message' => 'Document deleted.']);
    }
}
