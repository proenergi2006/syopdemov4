<?php

namespace App\Exports;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;


class PurchaseOrderInventoryExport implements FromCollection, WithHeadings, WithMapping
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function collection()
    {
        $q = DB::table('inventory_vendor_po as a')
            ->select(
                'a.*',
                'c.jenis_produk',
                'c.merk_dagang',
                'd.nama_vendor',
                'e.nama_terminal',
                'e.tanki_terminal',
                DB::raw('COALESCE(SUM(r.volume_terima),0) as vol_terima'),
                DB::raw('COALESCE(SUM(r.volume_bol),0) as vol_bl')
            )
            ->join('produk as c', 'a.id_produk', '=', 'c.id')
            ->join('master_vendor as d', 'a.id_vendor', '=', 'd.id')
            ->join('terminal as e', 'a.id_terminal', '=', 'e.id')
            ->leftJoin('inventory_vendor_receive as r', 'a.id_master', '=', 'r.id_po_supplier')
            ->where('a.harga_tebus', '>', 0)
            ->groupBy(
                'a.id_master',
                'c.jenis_produk',
                'c.merk_dagang',
                'd.nama_vendor',
                'e.nama_terminal',
                'e.tanki_terminal',
                'e.lokasi_terminal'
            );

        // 🔥 FRONTEND FILTER MATCHING

        if ($this->request->search) {
            $q->where('a.nomor_po', 'like', '%' . $this->request->search . '%');
        }

        if ($this->request->vendor) {
            $q->where('a.id_vendor', $this->request->vendor);
        }

        if ($this->request->terminal) {
            $q->where('a.id_terminal', $this->request->terminal);
        }

        if ($this->request->tanggal_awal && $this->request->tanggal_akhir) {
            $q->whereBetween('a.tanggal_inven', [
                $this->request->tanggal_awal,
                $this->request->tanggal_akhir
            ]);
        }

        // optional kalau nanti kamu pakai
        if ($this->request->status) {
            $q->where('a.disposisi_po', $this->request->status);
        }

        return $q->get();
    }

    public function headings(): array
    {
        return [
            'No',
            'Nomor PO',
            'Tanggal',
            'Vendor',
            'Terminal',
            'Tanki',
            'Produk',
            'Volume PO',
            'Volume BL',
            'Volume Terima',
            'Harga PO',
            'Harga Tebus',
            'Status',
        ];
    }

    public function map($row): array
    {
        static $no = 1;

        return [
            $no++,
            $row->nomor_po,
            date('d-m-Y', strtotime($row->tanggal_inven)),
            $row->nama_vendor,
            $row->nama_terminal,
            $row->tanki_terminal,
            $row->merk_dagang,
            number_format($row->volume_po),
            number_format($row->vol_bl),
            number_format($row->vol_terima),
            number_format($row->harga_po),
            number_format($row->harga_tebus),
            match ($row->disposisi_po ?? null) {
                4 => 'Terverifikasi',
                3 => 'Ditolak CFO',
                5 => 'Ditolak CEO',
                default => '-'
            },
        ];
    }
}