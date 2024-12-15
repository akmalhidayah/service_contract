<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Models\Notification;
use App\Models\Abnormal;
use App\Models\ScopeOfWork;
use App\Models\GambarTeknik;
use App\Models\Hpp1;
use App\Models\PurchaseOrder;
use App\Models\LHPP;
use App\Models\SPK;
use App\Models\Lpj;


class PKMDashboardController extends Controller
{
    public function index()
    {
        // Ambil semua notifikasi dengan relasi PurchaseOrder
        $notifications = Notification::with('purchaseOrder')->get();
    
        // Hitung total pekerjaan
        $totalPekerjaan = $notifications->count();
    
        // Hitung pekerjaan yang menunggu (progress < 100)
        $pekerjaanMenunggu = $notifications->filter(function ($notification) {
            return $notification->purchaseOrder && $notification->purchaseOrder->progress_pekerjaan < 100;
        })->count();
    
        // Hitung pekerjaan yang selesai (progress 100%)
        $pekerjaanSelesai = $notifications->filter(function ($notification) {
            return $notification->purchaseOrder && $notification->purchaseOrder->progress_pekerjaan === 100;
        })->count();
    
        // Hitung total progress (rata-rata dari semua pekerjaan)
        $totalProgress = $notifications->filter(function ($notification) {
            return $notification->purchaseOrder;
        })->pluck('purchaseOrder.progress_pekerjaan')->avg() ?? 0;
    
        // Data untuk daftar pekerjaan
        $targetDates = $notifications->filter(function ($notification) {
            return $notification->purchaseOrder && $notification->purchaseOrder->target_penyelesaian;
        })->map(function ($notification) {
            return [
                'date' => $notification->purchaseOrder->target_penyelesaian,
                'description' => "Pekerjaan: {$notification->notification_number}"
            ];
        })->values();
    
        // Kirim data ke view
        return view('pkm.dashboard', [
            'totalPekerjaan' => $totalPekerjaan,
            'pekerjaanMenunggu' => $pekerjaanMenunggu,
            'pekerjaanSelesai' => $pekerjaanSelesai,
            'totalProgress' => round($totalProgress, 2),
            'targetDates' => $targetDates,
        ]);
    }
    
    public function jobWaiting(Request $request)
    {
        // Ambil filter dari request
        $priority = $request->input('priority'); // Filter prioritas
        $search = $request->input('search'); // Filter pencarian berdasarkan nomor notifikasi
    
        // Ambil semua notifikasi dengan relasi PurchaseOrder
        $query = Notification::with('purchaseOrder')
            ->whereHas('purchaseOrder', function ($query) {
                $query->whereNotNull('approval_target'); // Hanya ambil data yang memiliki approval_target
            });
    
        // Filter berdasarkan prioritas jika diberikan
        if ($priority) {
            $query->where('priority', $priority);
        }
    
        // Filter berdasarkan pencarian nomor notifikasi jika diberikan
        if ($search) {
            $query->where('notification_number', 'like', '%' . $search . '%');
        }
    
        // Ambil data notifikasi yang sudah difilter
        $notifications = $query->orderBy('created_at', 'desc')->get();
    
        // Iterasi notifikasi dan tambahkan informasi tambahan
        $notifications->each(function ($notification) {
            // Cek apakah dokumen LHPP tersedia
            $notification->isLhppAvailable = LHPP::where('notification_number', $notification->notification_number)->exists();
    
            // Cek apakah dokumen LPJ tersedia
            $notification->isLpjAvailable = Lpj::where('notification_number', $notification->notification_number)->exists();
    
            // Cek apakah dokumen LPP tersedia (berasal dari LPJ - ppl_document_path)
            $notification->isLppAvailable = Lpj::where('notification_number', $notification->notification_number)
                ->whereNotNull('ppl_document_path')
                ->exists();
    
            // Cek apakah dokumen abnormalitas tersedia
            $notification->isAbnormalAvailable = Abnormal::where('notification_number', $notification->notification_number)->exists();
    
            // Cek apakah dokumen scope of work tersedia
            $notification->isScopeOfWorkAvailable = ScopeOfWork::where('notification_number', $notification->notification_number)->exists();
    
            // Cek apakah dokumen gambar teknik tersedia
            $notification->isGambarTeknikAvailable = GambarTeknik::where('notification_number', $notification->notification_number)->exists();
    
            // Cek apakah dokumen HPP tersedia dan ambil source_form serta total_amount
            $hpp = Hpp1::where('notification_number', $notification->notification_number)->first();
            if ($hpp) {
                $notification->isHppAvailable = true;
                $notification->source_form = $hpp->source_form;
                $notification->total_amount = $hpp->total_amount;
            } else {
                $notification->isHppAvailable = false;
            }
    
            // Cek apakah dokumen SPK tersedia
            $notification->isSpkAvailable = SPK::where('notification_number', $notification->notification_number)->exists();
        });
    
        // Filter notifikasi yang belum memiliki semua dokumen LHPP, LPJ, dan LPP
        $filteredNotifications = $notifications->reject(function ($notification) {
            return $notification->isLhppAvailable && $notification->isLpjAvailable && $notification->isLppAvailable;
        });
    
        // Konversi koleksi menjadi paginasi
        $page = $request->input('page', 1); // Halaman saat ini
        $perPage = 10; // Jumlah item per halaman
        $paginatedNotifications = new LengthAwarePaginator(
            $filteredNotifications->forPage($page, $perPage)->values(),
            $filteredNotifications->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
    
        // Kirim data ke view
        return view('pkm.jobwaiting', ['notifications' => $paginatedNotifications]);
    }

    public function updateProgress(Request $request, $notification_number)
    {
        // Validasi inputan progress, catatan, dan target penyelesaian
        $request->validate([
            'progress_pekerjaan' => 'required|integer|min:0|max:100',
            'catatan_pekerjaan' => 'nullable|string|max:1000',
            'target_penyelesaian' => 'nullable|date', // Validasi untuk target penyelesaian
        ]);
    
        // Cari atau buat PurchaseOrder berdasarkan notification_number
        $purchaseOrder = PurchaseOrder::firstOrCreate(
            ['notification_number' => $notification_number],
            ['purchase_order_number' => null]
        );
    
        // Update progress pekerjaan, catatan pekerjaan, dan target penyelesaian
        $purchaseOrder->progress_pekerjaan = $request->input('progress_pekerjaan');
        $purchaseOrder->catatan = $request->input('catatan');
        $purchaseOrder->target_penyelesaian = $request->input('target_penyelesaian'); // Simpan target penyelesaian
        $purchaseOrder->save();
    
        return redirect()->back()->with('success', 'Progress, catatan, dan target penyelesaian berhasil diperbarui.');
    }

    public function laporan(Request $request)
    {
        $query = Notification::with(['lhpp', 'lpj']);
    
        if ($request->has('notification_number')) {
            $query->where('notification_number', 'like', '%' . $request->input('notification_number') . '%');
        }
    
        $notifications = $query->orderBy('created_at', 'desc')->paginate(10);
    
        return view('pkm.laporan', compact('notifications'));
    }
    

    public function showLHPP($notification_number)
    {
        $lhpp = LHPP::where('notification_number', $notification_number)->firstOrFail();
        return view('pkm.showlhpp', compact('lhpp'));
    }

    public function notificationDetail($notification_number)
{
    $notification = Notification::with(['lhpp', 'lpj'])->where('notification_number', $notification_number)->firstOrFail();
    return view('pkm.notification-detail', compact('notification'));
}

}
