<?php

namespace App\Http\Controllers\Approval;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Abnormal;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use App\Mail\AbnormalitasNotification; 
use Illuminate\Support\Facades\Http;

class ApprovalController extends Controller
{
    public function index()
    {
        $user = auth()->user();
    
        // Ambil dokumen berdasarkan unit_work dan jabatan
        $abnormals = Abnormal::where('unit_kerja', $user->unit_work)
                              ->where(function($query) use ($user) {
                                  if ($user->jabatan == 'Manager') {
                                      // Tampilkan semua dokumen, tapi bedakan berdasarkan apakah sudah ditandatangani atau belum
                                      $query->whereNull('manager_signature')
                                            ->orWhereNotNull('manager_signature');
                                  } elseif ($user->jabatan == 'Senior Manager') {
                                      // Tampilkan dokumen yang sudah ditandatangani oleh Manager, tapi belum oleh Senior Manager
                                      $query->whereNotNull('manager_signature')
                                            ->orWhereNotNull('senior_manager_signature');
                                  }
                              })
                              ->orderByRaw("CASE 
                                  WHEN manager_signature IS NULL THEN 0 
                                  WHEN senior_manager_signature IS NULL THEN 1 
                                  ELSE 2 
                              END")
                              ->get();
    
        return view('approval.index', compact('abnormals'));
    }
    
    public function saveSignature(Request $request, $signType, $notificationNumber)
    {
        try {
            \Log::info('Received signType: ' . $signType);
            \Log::info('Notification Number: ' . $notificationNumber);
            \Log::info('Signature: ' . $request->tanda_tangan);
    
            $request->validate([
                'tanda_tangan' => 'required',
            ]);
    
            // Simpan data tanda tangan di database
            $abnormal = Abnormal::where('notification_number', $notificationNumber)->firstOrFail();
    
            if ($signType == 'manager') {
                $abnormal->manager_signature = $request->tanda_tangan;
                $abnormal->user_id = auth()->id(); // Menyimpan ID pengguna yang menandatangani sebagai manager
                $abnormal->save();
    
                // Kirim notifikasi WhatsApp ke Senior Manager setelah Manager menandatangani
                $seniorManagers = User::where('unit_work', $abnormal->unit_kerja)
                                      ->where('jabatan', 'Senior Manager')
                                      ->get();
    
                foreach ($seniorManagers as $seniorManager) {
                    $message = "Permintaan Approval Pekerjaan Abnormalitas :\nNomor Notifikasi: {$abnormal->notification_number}\nNama Pekerjaan: {$abnormal->abnormal_title}\nUnit Kerja: {$abnormal->unit_kerja}\nDeskripsi Masalah: {$abnormal->problem_description}\n\nSilakan login dan tanda tangani dokumen:\nhttps://sectionofworkshop.com/approval";
    
                    Http::withHeaders([
                        'Authorization' => 'KBTe2RszCgc6aWhYapcv' // API key Fonnte Anda
                    ])->post('https://api.fonnte.com/send', [
                        'target' => $seniorManager->whatsapp_number,
                        'message' => $message,
                    ]);
    
                    \Log::info('WhatsApp notification sent to Senior Manager: ' . $seniorManager->whatsapp_number);
                }
            } elseif ($signType == 'senior_manager') {
                $abnormal->senior_manager_signature = $request->tanda_tangan;
                $abnormal->user_id = auth()->id(); // Menyimpan ID pengguna yang menandatangani sebagai senior manager
                $abnormal->save();
            }
    
            return response()->json(['message' => 'Signature saved successfully!'], 200);
        } catch (\Exception $e) {
            \Log::error($e->getMessage());
            return response()->json(['error' => 'Terjadi kesalahan saat menyimpan tanda tangan', 'details' => $e->getMessage()], 500);
        }
    }
    public function getOldSignature($signType, $notificationNumber)
    {
        $user = auth()->user();
    
        // Cari dokumen yang telah ditandatangani oleh pengguna yang sedang login
        $abnormal = Abnormal::where('user_id', $user->id)
                            ->whereNotNull($signType . '_signature')
                            ->latest('created_at') // Mengambil tanda tangan terbaru dari pengguna
                            ->first();
    
        if ($abnormal) {
            if ($signType === 'manager' && $abnormal->manager_signature) {
                return response()->json(['signature' => $abnormal->manager_signature]);
            } elseif ($signType === 'senior_manager' && $abnormal->senior_manager_signature) {
                return response()->json(['signature' => $abnormal->senior_manager_signature]);
            }
        }
    
        return response()->json(['signature' => null, 'message' => 'Tidak ada tanda tangan lama yang ditemukan.'], 404);
    }
    
}    
