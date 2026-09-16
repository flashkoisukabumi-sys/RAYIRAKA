<?php
require_once 'db.php';

// Auto-migrate database structure for Customer tiers & Product price tiers if not exist
try {
    $pdo->exec("ALTER TABLE customer ADD COLUMN tipe ENUM('ritel','grosir','distributor') DEFAULT 'ritel'");
} catch(PDOException $e) {}

try {
    $pdo->exec("ALTER TABLE produk ADD COLUMN harga_ritel DECIMAL(15,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE produk ADD COLUMN harga_grosir DECIMAL(15,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE produk ADD COLUMN harga_distributor DECIMAL(15,2) DEFAULT 0");
} catch(PDOException $e) {}

$page = $_GET['page'] ?? 'dashboard';
$action = $_GET['action'] ?? '';
$id = $_GET['id'] ?? null;
$error_msg = '';
$success_msg = '';

if (isset($_GET['import']) && $_GET['import'] == 'sukses') {
    $success_msg = "Data produk berhasil di-import dari file CSV!";
}

// Handle CRUD Produk
if ($page == 'produk' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_produk'])) {
    $kode = $_POST['kode'];
    $nama = $_POST['nama'];
    $harga_beli = $_POST['harga_beli'];
    $harga_ritel = $_POST['harga_ritel'];
    $harga_grosir = $_POST['harga_grosir'];
    $harga_distributor = $_POST['harga_distributor'];
    $stok = $_POST['stok'];
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE produk SET kode=?, nama=?, harga_beli=?, harga_ritel=?, harga_grosir=?, harga_distributor=?, stok=? WHERE id=?");
        $stmt->execute([$kode, $nama, $harga_beli, $harga_ritel, $harga_grosir, $harga_distributor, $stok, $_POST['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO produk (kode, nama, harga_beli, harga_ritel, harga_grosir, harga_distributor, stok) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$kode, $nama, $harga_beli, $harga_ritel, $harga_grosir, $harga_distributor, $stok]);
    }
    header("Location: index.php?page=produk");
    exit;
}



// Handle CRUD Customer
if ($page == 'customer' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_customer'])) {
    $nama = $_POST['nama'];
    $alamat = $_POST['alamat'];
    $telepon = $_POST['telepon'];
    $tipe = $_POST['tipe'];
    
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE customer SET nama=?, alamat=?, telepon=?, tipe=? WHERE id=?");
        $stmt->execute([$nama, $alamat, $telepon, $tipe, $_POST['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO customer (nama, alamat, telepon, tipe) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nama, $alamat, $telepon, $tipe]);
    }
    header("Location: index.php?page=customer");
    exit;
}
if ($page == 'customer' && $action == 'delete' && $id) {
    $pdo->prepare("DELETE FROM customer WHERE id=?")->execute([$id]);
    header("Location: index.php?page=customer");
    exit;
}

// Handle CRUD Supplier
if ($page == 'supplier' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_supplier'])) {
    $nama = $_POST['nama'];
    $perusahaan = $_POST['perusahaan'];
    $alamat = $_POST['alamat'];
    $telepon = $_POST['telepon'];
    if (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("UPDATE supplier SET nama=?, perusahaan=?, alamat=?, telepon=? WHERE id=?");
        $stmt->execute([$nama, $perusahaan, $alamat, $telepon, $_POST['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO supplier (nama, perusahaan, alamat, telepon) VALUES (?, ?, ?, ?)");
        $stmt->execute([$nama, $perusahaan, $alamat, $telepon]);
    }
    header("Location: index.php?page=supplier");
    exit;
}
if ($page == 'supplier' && $action == 'delete' && $id) {
    $pdo->prepare("DELETE FROM supplier WHERE id=?")->execute([$id]);
    header("Location: index.php?page=supplier");
    exit;
}

// Handle Transaksi Create / Update (Penjualan & Pembelian)
if (in_array($page, ['penjualan', 'pembelian']) && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['simpan_transaksi'])) {
    $jenis = $page; 
    $edit_id = $_POST['edit_id'] ?? null;
    $tipe_bayar = $_POST['tipe_bayar'];
    $partner_id = $_POST['partner_id'];
    $produk_ids = $_POST['produk_id'];
    $jumlahs = $_POST['jumlah'];
    $hargas = $_POST['harga'];
    
    if (!empty($edit_id)) {
        $old_trx = $pdo->prepare("SELECT * FROM transaksi WHERE id = ?");
        $old_trx->execute([$edit_id]);
        $old_t = $old_trx->fetch();
        
        if ($old_t) {
            $old_dt = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = ?");
            $old_dt->execute([$edit_id]);
            $old_details = $old_dt->fetchAll();
            
            foreach ($old_details as $od) {
                if ($old_t['jenis'] == 'penjualan') {
                    $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?")->execute([$od['jumlah'], $od['produk_id']]);
                } else {
                    $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?")->execute([$od['jumlah'], $od['produk_id']]);
                }
            }
            $pdo->prepare("DELETE FROM transaksi_detail WHERE transaksi_id = ?")->execute([$edit_id]);
        }
    } else {
        $prefix = ($jenis == 'penjualan') ? 'S' : 'P';
        $no_invoice = "INV-" . $prefix . "-" . date('YmdHis');
    }

    if ($jenis == 'penjualan') {
        for ($i = 0; $i < count($produk_ids); $i++) {
            $pid = $produk_ids[$i];
            $qty_beli = intval($jumlahs[$i]);
            
            $stk_check = $pdo->prepare("SELECT stok, nama FROM produk WHERE id = ?");
            $stk_check->execute([$pid]);
            $p_data = $stk_check->fetch();
            
            if ($p_data && $qty_beli > $p_data['stok']) {
                $error_msg = "Transaksi Gagal! Stok produk '{$p_data['nama']}' tidak mencukupi (Stok saat ini: {$p_data['stok']}).";
            }
        }
    }

    if (empty($error_msg)) {
        $total = 0;
        for ($i = 0; $i < count($produk_ids); $i++) {
            $total += $jumlahs[$i] * $hargas[$i];
        }
        
        if ($tipe_bayar == 'kredit') {
            $dp = floatval($_POST['dp']);
            $sisa = $total - $dp;
            $status = ($sisa <= 0) ? 'lunas' : 'belum_lunas';
        } else {
            $dp = $total;
            $sisa = 0;
            $status = 'lunas';
        }
        
        if (!empty($edit_id)) {
            $stmt = $pdo->prepare("UPDATE transaksi SET tipe_bayar=?, partner_id=?, total=?, dp=?, sisa=?, status=? WHERE id=?");
            $stmt->execute([$tipe_bayar, $partner_id, $total, $dp, $sisa, $status, $edit_id]);
            $transaksi_id = $edit_id;
        } else {
            $stmt = $pdo->prepare("INSERT INTO transaksi (no_invoice, jenis, tipe_bayar, partner_id, total, dp, sisa, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$no_invoice, $jenis, $tipe_bayar, $partner_id, $total, $dp, $sisa, $status]);
            $transaksi_id = $pdo->lastInsertId();
        }
        
        for ($i = 0; $i < count($produk_ids); $i++) {
            $pid = $produk_ids[$i];
            $qty = intval($jumlahs[$i]);
            $prc = floatval($hargas[$i]);
            $sub = $qty * $prc;
            
            $stmtDetail = $pdo->prepare("INSERT INTO transaksi_detail (transaksi_id, produk_id, jumlah, harga, subtotal) VALUES (?, ?, ?, ?, ?)");
            $stmtDetail->execute([$transaksi_id, $pid, $qty, $prc, $sub]);
            
            if ($jenis == 'penjualan') {
                $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?")->execute([$qty, $pid]);
            } else {
                $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?")->execute([$qty, $pid]);
            }
        }
        
        $redirect_page = ($jenis == 'penjualan') ? 'daftar_penjualan' : 'daftar_pembelian';
        header("Location: index.php?page=" . $redirect_page);
        exit;
    }
}

// Handle Delete Transaksi
if (in_array($page, ['daftar_penjualan', 'daftar_pembelian']) && $action == 'delete' && $id) {
    $trx_chk = $pdo->prepare("SELECT * FROM transaksi WHERE id = ?");
    $trx_chk->execute([$id]);
    $trx_data = $trx_chk->fetch();
    
    if ($trx_data) {
        $dt_chk = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = ?");
        $dt_chk->execute([$id]);
        $details = $dt_chk->fetchAll();
        
        foreach ($details as $d) {
            if ($trx_data['jenis'] == 'penjualan') {
                $pdo->prepare("UPDATE produk SET stok = stok + ? WHERE id = ?")->execute([$d['jumlah'], $d['produk_id']]);
            } else {
                $pdo->prepare("UPDATE produk SET stok = stok - ? WHERE id = ?")->execute([$d['jumlah'], $d['produk_id']]);
            }
        }
        
        $pdo->prepare("DELETE FROM cicilan WHERE transaksi_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM transaksi_detail WHERE transaksi_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM transaksi WHERE id = ?")->execute([$id]);
        
        $redirect_page = ($trx_data['jenis'] == 'penjualan') ? 'daftar_penjualan' : 'daftar_pembelian';
        header("Location: index.php?page=" . $redirect_page);
        exit;
    }
}

// Handle Pembayaran Cicilan
if ($page == 'cicilan' && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['bayar_cicilan'])) {
    $transaksi_id = $_POST['transaksi_id'];
    $bayar = floatval($_POST['jumlah_bayar']);
    
    $trx = $pdo->prepare("SELECT * FROM transaksi WHERE id = ?");
    $trx->execute([$transaksi_id]);
    $t = $trx->fetch();
    
    if ($t) {
        if ($bayar > $t['sisa']) {
            $error_msg = "Pembayaran Gagal! Jumlah cicilan (Rp " . number_format($bayar, 0, ',', '.') . ") melebihi sisa hutang (Rp " . number_format($t['sisa'], 0, ',', '.') . ").";
        } else {
            $sisa_baru = max(0, $t['sisa'] - $bayar);
            $status_baru = ($sisa_baru == 0) ? 'lunas' : 'belum_lunas';
            $dp_baru = $t['dp'] + $bayar;
            
            $pdo->prepare("UPDATE transaksi SET dp = ?, sisa = ?, status = ? WHERE id = ?")->execute([$dp_baru, $sisa_baru, $status_baru, $transaksi_id]);
            $pdo->prepare("INSERT INTO cicilan (transaksi_id, jumlah_bayar, sisa_sesudah) VALUES (?, ?, ?)")->execute([$transaksi_id, $bayar, $sisa_baru]);
            
            header("Location: index.php?page=invoice_cicilan&trx_id=" . $transaksi_id);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RAYIRAKA - 165 - Produsen Alat Pancing Sukabumi</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            body * { visibility: hidden; }
            #printable-area, #printable-area * { visibility: visible; }
            #printable-area { position: absolute; left: 0; top: 0; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 font-sans min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-indigo-900 text-white shadow-md relative z-50">
        <div class="max-w-7xl mx-auto px-4 py-4 flex flex-col md:flex-row justify-between items-center gap-3">
            <div class="text-center md:text-left">
                <h1 class="text-xl md:text-2xl font-black tracking-wider">RAYIRAKA - 165</h1>
                <p class="text-[10px] md:text-xs text-indigo-200 uppercase tracking-widest font-semibold">Produsen Alat Pancing Sukabumi</p>
            </div>
            <nav class="flex flex-wrap justify-center md:justify-end gap-1.5 md:gap-2 text-xs md:text-sm font-medium items-center">
                <a href="index.php?page=dashboard" class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition <?= $page=='dashboard'?'bg-indigo-700':'hover:bg-indigo-800' ?>">Dashboard</a>
                
                <!-- Dropdown Master Data -->
                <div class="relative group">
                    <button class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition flex items-center gap-1 <?= in_array($page, ['produk', 'customer', 'supplier']) ? 'bg-indigo-700' : 'hover:bg-indigo-800' ?>">
                        Master Data 
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute left-0 md:left-auto md:right-0 mt-1 w-44 bg-white text-slate-800 border border-slate-200 rounded shadow-lg hidden group-hover:block z-50">
                        <a href="index.php?page=produk" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='produk'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Produk</a>
                        <a href="index.php?page=customer" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='customer'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Customer</a>
                        <a href="index.php?page=supplier" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='supplier'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Supplier</a>
                    </div>
                </div>

                <!-- Dropdown Penjualan -->
                <div class="relative group">
                    <button class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition flex items-center gap-1 <?= in_array($page, ['penjualan', 'daftar_penjualan']) ? 'bg-indigo-700' : 'hover:bg-indigo-800' ?>">
                        Penjualan 
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute left-0 md:left-auto md:right-0 mt-1 w-44 bg-white text-slate-800 border border-slate-200 rounded shadow-lg hidden group-hover:block z-50">
                        <a href="index.php?page=penjualan" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='penjualan'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Buat Penjualan</a>
                        <a href="index.php?page=daftar_penjualan" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='daftar_penjualan'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Riwayat Penjualan</a>
                    </div>
                </div>

                <!-- Dropdown Pembelian -->
                <div class="relative group">
                    <button class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition flex items-center gap-1 <?= in_array($page, ['pembelian', 'daftar_pembelian']) ? 'bg-indigo-700' : 'hover:bg-indigo-800' ?>">
                        Pembelian 
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute left-0 md:left-auto md:right-0 mt-1 w-44 bg-white text-slate-800 border border-slate-200 rounded shadow-lg hidden group-hover:block z-50">
                        <a href="index.php?page=pembelian" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='pembelian'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Buat Pembelian</a>
                        <a href="index.php?page=daftar_pembelian" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='daftar_pembelian'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Riwayat Pembelian</a>
                    </div>
                </div>

                <a href="index.php?page=cicilan" class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition <?= $page=='cicilan'?'bg-indigo-700':'hover:bg-indigo-800' ?>">Kredit</a>

                <!-- Dropdown Laporan -->
                <div class="relative group">
                    <button class="px-2.5 py-1.5 md:px-3 md:py-2 rounded transition flex items-center gap-1 <?= in_array($page, ['laporan', 'laporan_stok']) ? 'bg-indigo-700' : 'hover:bg-indigo-800' ?>">
                        Laporan 
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div class="absolute left-0 md:left-auto md:right-0 mt-1 w-48 bg-white text-slate-800 border border-slate-200 rounded shadow-lg hidden group-hover:block z-50">
                        <a href="index.php?page=laporan" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='laporan'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Laba / Rugi Kotor</a>
                        <a href="index.php?page=laporan_stok" class="block px-4 py-2 hover:bg-indigo-50 <?= $page=='laporan_stok'?'bg-indigo-100 font-semibold text-indigo-900':'' ?>">Laporan Stok Barang</a>
                    </div>
                </div>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-3 md:px-4 py-6 md:py-8 flex-grow w-full">
        <?php if (!empty($error_msg)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 text-xs md:text-sm font-semibold">
                <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_msg)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6 text-xs md:text-sm font-semibold">
                <?= $success_msg ?>
            </div>
        <?php endif; ?>

        <?php if ($page == 'dashboard'): ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 md:gap-6 mb-8">
                <?php
                $tot_prod = $pdo->query("SELECT COUNT(*) FROM produk")->fetchColumn();
                $tot_cust = $pdo->query("SELECT COUNT(*) FROM customer")->fetchColumn();
                $tot_supp = $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
                $tot_trx = $pdo->query("SELECT COUNT(*) FROM transaksi")->fetchColumn();
                ?>
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <p class="text-[10px] md:text-xs text-slate-500 font-semibold uppercase">Total Produk</p>
                    <p class="text-2xl md:text-3xl font-bold text-indigo-900 mt-2"><?= $tot_prod ?></p>
                </div>
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <p class="text-[10px] md:text-xs text-slate-500 font-semibold uppercase">Total Customer</p>
                    <p class="text-2xl md:text-3xl font-bold text-indigo-900 mt-2"><?= $tot_cust ?></p>
                </div>
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <p class="text-[10px] md:text-xs text-slate-500 font-semibold uppercase">Total Supplier</p>
                    <p class="text-2xl md:text-3xl font-bold text-indigo-900 mt-2"><?= $tot_supp ?></p>
                </div>
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <p class="text-[10px] md:text-xs text-slate-500 font-semibold uppercase">Total Transaksi</p>
                    <p class="text-2xl md:text-3xl font-bold text-indigo-900 mt-2"><?= $tot_trx ?></p>
                </div>
            </div>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <h3 class="text-base md:text-lg font-bold text-slate-800 mb-4">Transaksi Terbaru</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs md:text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th class="p-2.5 md:p-3">Invoice</th>
                                <th class="p-2.5 md:p-3">Tanggal</th>
                                <th class="p-2.5 md:p-3">Jenis</th>
                                <th class="p-2.5 md:p-3">Bayar</th>
                                <th class="p-2.5 md:p-3">Total</th>
                                <th class="p-2.5 md:p-3">Status</th>
                                <th class="p-2.5 md:p-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $st = $pdo->query("SELECT * FROM transaksi ORDER BY id DESC LIMIT 5");
                            while($row = $st->fetch()):
                            ?>
                            <tr class="border-b">
                                <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['no_invoice'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['tanggal'] ?></td>
                                <td class="p-2.5 md:p-3 uppercase font-medium text-xs whitespace-nowrap"><span class="px-2 py-0.5 rounded <?= $row['jenis']=='penjualan'?'bg-green-100 text-green-800':'bg-blue-100 text-blue-800' ?>"><?= $row['jenis'] ?></span></td>
                                <td class="p-2.5 md:p-3 uppercase text-xs whitespace-nowrap"><?= $row['tipe_bayar'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><span class="px-2 py-0.5 rounded text-xs <?= $row['status']=='lunas'?'bg-emerald-100 text-emerald-800':'bg-amber-100 text-amber-800' ?>"><?= strtoupper($row['status']) ?></span></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><a href="index.php?page=invoice&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold hover:underline">Invoice</a></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($page == 'produk'): ?>
            <?php 
            $editData = null;
            if ($action == 'edit' && $id) {
                $st = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
                $st->execute([$id]);
                $editData = $st->fetch();
            }
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <div class="space-y-6">
                    <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                        <h3 class="text-base md:text-lg font-bold mb-4"><?= $editData ? 'Edit Produk' : 'Tambah Produk Baru' ?></h3>
                        <form method="POST" class="space-y-3.5">
                            <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Kode Produk</label>
                                <input type="text" name="kode" required value="<?= $editData['kode'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Nama Produk</label>
                                <input type="text" name="nama" required value="<?= $editData['nama'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Harga Beli (HPP)</label>
                                <input type="number" step="any" name="harga_beli" required value="<?= $editData['harga_beli'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Harga Ritel</label>
                                <input type="number" step="any" name="harga_ritel" required value="<?= $editData['harga_ritel'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Harga Grosir</label>
                                <input type="number" step="any" name="harga_grosir" required value="<?= $editData['harga_grosir'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Harga Distributor</label>
                                <input type="number" step="any" name="harga_distributor" required value="<?= $editData['harga_distributor'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs md:text-sm font-semibold mb-1">Stok</label>
                                <input type="number" name="stok" required value="<?= $editData['stok'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                            </div>
                            <button type="submit" name="save_produk" class="w-full bg-indigo-600 text-white font-semibold py-2.5 rounded hover:bg-indigo-700 transition text-sm">Simpan Produk</button>
                        </form>
                    </div>

                    <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                                                                  </div>
                </div>

                <div class="lg:col-span-2 bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <h3 class="text-base md:text-lg font-bold mb-4">Daftar Produk Alat Pancing</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs md:text-sm">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="p-2.5 md:p-3">Kode</th>
                                    <th class="p-2.5 md:p-3">Nama Produk</th>
                                    <th class="p-2.5 md:p-3">HPP</th>
                                    <th class="p-2.5 md:p-3">Ritel</th>
                                    <th class="p-2.5 md:p-3">Grosir</th>
                                    <th class="p-2.5 md:p-3">Distributor</th>
                                    <th class="p-2.5 md:p-3">Stok</th>
                                    <th class="p-2.5 md:p-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT * FROM produk ORDER BY id DESC");
                                while($row = $st->fetch()):
                                ?>
                                <tr class="border-b">
                                    <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['kode'] ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['nama'] ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['harga_beli'], 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['harga_ritel'], 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['harga_grosir'], 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['harga_distributor'], 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 font-bold whitespace-nowrap <?= $row['stok'] <= 0 ? 'text-red-600' : '' ?>"><?= $row['stok'] ?></td>
                                    <td class="p-2.5 md:p-3 space-x-2 whitespace-nowrap">
                                        <a href="index.php?page=produk&action=edit&id=<?= $row['id'] ?>" class="text-blue-600 hover:underline">Edit</a>
                                        <a href="index.php?page=produk&action=delete&id=<?= $row['id'] ?>" onclick="return confirm('Hapus produk ini?')" class="text-red-600 hover:underline">Hapus</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'customer'): ?>
            <?php 
            $editData = null;
            if ($action == 'edit' && $id) {
                $st = $pdo->prepare("SELECT * FROM customer WHERE id = ?");
                $st->execute([$id]);
                $editData = $st->fetch();
            }
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200 h-fit">
                    <h3 class="text-base md:text-lg font-bold mb-4"><?= $editData ? 'Edit Customer' : 'Tambah Customer' ?></h3>
                    <form method="POST" class="space-y-3.5">
                        <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Nama Customer</label>
                            <input type="text" name="nama" required value="<?= $editData['nama'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Tipe Customer</label>
                            <select name="tipe" required class="w-full border rounded p-2 text-sm">
                                <option value="ritel" <?= ($editData['tipe'] ?? '')=='ritel'?'selected':'' ?>>Ritel</option>
                                <option value="grosir" <?= ($editData['tipe'] ?? '')=='grosir'?'selected':'' ?>>Grosir</option>
                                <option value="distributor" <?= ($editData['tipe'] ?? '')=='distributor'?'selected':'' ?>>Distributor</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Alamat</label>
                            <textarea name="alamat" rows="3" class="w-full border rounded p-2 text-sm"><?= $editData['alamat'] ?? '' ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Telepon</label>
                            <input type="text" name="telepon" value="<?= $editData['telepon'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                        <button type="submit" name="save_customer" class="w-full bg-indigo-600 text-white font-semibold py-2.5 rounded hover:bg-indigo-700 transition text-sm">Simpan Customer</button>
                    </form>
                </div>
                <div class="lg:col-span-2 bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <h3 class="text-base md:text-lg font-bold mb-4">Daftar Customer</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs md:text-sm">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="p-2.5 md:p-3">Nama</th>
                                    <th class="p-2.5 md:p-3">Tipe</th>
                                    <th class="p-2.5 md:p-3">Alamat</th>
                                    <th class="p-2.5 md:p-3">Telepon</th>
                                    <th class="p-2.5 md:p-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT * FROM customer ORDER BY id DESC");
                                while($row = $st->fetch()):
                                ?>
                                <tr class="border-b">
                                    <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['nama'] ?></td>
                                    <td class="p-2.5 md:p-3 uppercase text-xs font-bold text-indigo-700 whitespace-nowrap"><?= $row['tipe'] ?? 'ritel' ?></td>
                                    <td class="p-2.5 md:p-3"><?= $row['alamat'] ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['telepon'] ?></td>
                                    <td class="p-2.5 md:p-3 space-x-2 whitespace-nowrap">
                                        <a href="index.php?page=customer&action=edit&id=<?= $row['id'] ?>" class="text-blue-600 hover:underline">Edit</a>
                                        <a href="index.php?page=customer&action=delete&id=<?= $row['id'] ?>" onclick="return confirm('Hapus customer ini?')" class="text-red-600 hover:underline">Hapus</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'supplier'): ?>
            <?php 
            $editData = null;
            if ($action == 'edit' && $id) {
                $st = $pdo->prepare("SELECT * FROM supplier WHERE id = ?");
                $st->execute([$id]);
                $editData = $st->fetch();
            }
            ?>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 md:gap-8">
                <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200 h-fit">
                    <h3 class="text-base md:text-lg font-bold mb-4"><?= $editData ? 'Edit Supplier' : 'Tambah Supplier' ?></h3>
                    <form method="POST" class="space-y-3.5">
                        <input type="hidden" name="id" value="<?= $editData['id'] ?? '' ?>">
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Nama Supplier</label>
                            <input type="text" name="nama" required value="<?= $editData['nama'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Perusahaan</label>
                            <input type="text" name="perusahaan" value="<?= $editData['perusahaan'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Alamat</label>
                            <textarea name="alamat" rows="2" class="w-full border rounded p-2 text-sm"><?= $editData['alamat'] ?? '' ?></textarea>
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Telepon</label>
                            <input type="text" name="telepon" value="<?= $editData['telepon'] ?? '' ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                        <button type="submit" name="save_supplier" class="w-full bg-indigo-600 text-white font-semibold py-2.5 rounded hover:bg-indigo-700 transition text-sm">Simpan Supplier</button>
                    </form>
                </div>
                <div class="lg:col-span-2 bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                    <h3 class="text-base md:text-lg font-bold mb-4">Daftar Supplier</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs md:text-sm">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="p-2.5 md:p-3">Nama</th>
                                    <th class="p-2.5 md:p-3">Perusahaan</th>
                                    <th class="p-2.5 md:p-3">Alamat</th>
                                    <th class="p-2.5 md:p-3">Telepon</th>
                                    <th class="p-2.5 md:p-3">Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $st = $pdo->query("SELECT * FROM supplier ORDER BY id DESC");
                                while($row = $st->fetch()):
                                ?>
                                <tr class="border-b">
                                    <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['nama'] ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['perusahaan'] ?></td>
                                    <td class="p-2.5 md:p-3"><?= $row['alamat'] ?></td>
                                    <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['telepon'] ?></td>
                                    <td class="p-2.5 md:p-3 space-x-2 whitespace-nowrap">
                                        <a href="index.php?page=supplier&action=edit&id=<?= $row['id'] ?>" class="text-blue-600 hover:underline">Edit</a>
                                        <a href="index.php?page=supplier&action=delete&id=<?= $row['id'] ?>" onclick="return confirm('Hapus supplier ini?')" class="text-red-600 hover:underline">Hapus</a>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'penjualan' || $page == 'pembelian'): ?>
            <?php
            $edit_trx = null;
            $edit_details = [];
            if ($action == 'edit' && $id) {
                $st_trx = $pdo->prepare("SELECT * FROM transaksi WHERE id = ? AND jenis = ?");
                $st_trx->execute([$id, $page]);
                $edit_trx = $st_trx->fetch();
                if ($edit_trx) {
                    $st_det = $pdo->prepare("SELECT * FROM transaksi_detail WHERE transaksi_id = ?");
                    $st_det->execute([$id]);
                    $edit_details = $st_det->fetchAll();
                }
            }
            ?>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <h3 class="text-lg md:text-xl font-bold mb-6 uppercase"><?= $edit_trx ? 'Edit' : 'Form' ?> Transaksi <?= $page ?></h3>
                <form method="POST" class="space-y-6" onsubmit="return validateForm(event, '<?= $page ?>')">
                    <input type="hidden" name="edit_id" value="<?= $edit_trx['id'] ?? '' ?>">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Pilih <?= $page=='penjualan'?'Customer':'Supplier' ?></label>
                            <select name="partner_id" id="partner_select" required class="w-full border rounded p-2 text-sm" onchange="onPartnerChange(this)">
                                <option value="">-- Pilih --</option>
                                <?php
                                if ($page == 'penjualan') {
                                    $res = $pdo->query("SELECT * FROM customer");
                                    while($r = $res->fetch()):
                                        $tipe_cust = $r['tipe'] ?? 'ritel';
                                        $sel = (isset($edit_trx['partner_id']) && $edit_trx['partner_id'] == $r['id']) ? 'selected' : '';
                                ?>
                                <option value="<?= $r['id'] ?>" data-tipe="<?= $tipe_cust ?>" <?= $sel ?>><?= $r['nama'] ?> (Tipe: <?= strtoupper($tipe_cust) ?>)</option>
                                <?php 
                                    endwhile;
                                } else {
                                    $res = $pdo->query("SELECT * FROM supplier");
                                    while($r = $res->fetch()):
                                        $sel = (isset($edit_trx['partner_id']) && $edit_trx['partner_id'] == $r['id']) ? 'selected' : '';
                                ?>
                                <option value="<?= $r['id'] ?>" <?= $sel ?>><?= $r['nama'] ?> <?= isset($r['perusahaan'])?'- '.$r['perusahaan']:'' ?></option>
                                <?php 
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs md:text-sm font-semibold mb-1">Tipe Pembayaran</label>
                            <select name="tipe_bayar" id="tipe_bayar" onchange="toggleDP()" required class="w-full border rounded p-2 text-sm">
                                <option value="cash" <?= (isset($edit_trx['tipe_bayar']) && $edit_trx['tipe_bayar']=='cash')?'selected':'' ?>>Cash</option>
                                <option value="kredit" <?= (isset($edit_trx['tipe_bayar']) && $edit_trx['tipe_bayar']=='kredit')?'selected':'' ?>>Kredit</option>
                            </select>
                        </div>
                        <div id="dp_container" class="<?= (isset($edit_trx['tipe_bayar']) && $edit_trx['tipe_bayar']=='kredit')?'':'hidden' ?>">
                            <label class="block text-xs md:text-sm font-semibold mb-1">Deposit Payment (DP)</label>
                            <input type="number" step="any" name="dp" id="dp_input" value="<?= $edit_trx['dp'] ?? 0 ?>" class="w-full border rounded p-2 text-sm">
                        </div>
                    </div>

                    <div>
                        <h4 class="font-bold text-sm md:text-md mb-3">Item Produk</h4>
                        <div id="items-container" class="space-y-3">
                            <?php if (!empty($edit_details)): ?>
                                <?php foreach ($edit_details as $ed): ?>
                                <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center item-row bg-slate-50 sm:bg-transparent p-3 sm:p-0 border sm:border-0 rounded-lg">
                                    <select name="produk_id[]" required class="w-full sm:flex-grow border rounded p-2 text-sm produk-select" onchange="updateHargaDanStok(this)">
                                        <option value="">-- Pilih Produk --</option>
                                        <?php
                                        $prod = $pdo->query("SELECT * FROM produk");
                                        while($p = $prod->fetch()):
                                            $h_ritel = $p['harga_ritel'] ?? 0;
                                            $h_grosir = $p['harga_grosir'] ?? 0;
                                            $h_dist = $p['harga_distributor'] ?? 0;
                                            $h_beli = $p['harga_beli'] ?? 0;
                                            $sel_p = ($p['id'] == $ed['produk_id']) ? 'selected' : '';
                                        ?>
                                        <option value="<?= $p['id'] ?>" <?= $sel_p ?>
                                                data-ritel="<?= $h_ritel ?>" 
                                                data-grosir="<?= $h_grosir ?>" 
                                                data-distributor="<?= $h_dist ?>" 
                                                data-beli="<?= $h_beli ?>" 
                                                data-stok="<?= $p['stok'] ?>">
                                            <?= $p['nama'] ?> (Stok: <?= $p['stok'] ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <div class="flex gap-2 w-full sm:w-auto">
                                        <input type="number" name="jumlah[]" placeholder="Qty" value="<?= $ed['jumlah'] ?>" min="1" required class="w-1/2 sm:w-24 border rounded p-2 text-sm qty-input" onchange="hitungTotal()">
                                        <input type="number" step="any" name="harga[]" placeholder="Harga" value="<?= $ed['harga'] ?>" required class="w-1/2 sm:w-36 border rounded p-2 text-sm harga-input" onchange="hitungTotal()">
                                    </div>
                                    <button type="button" onclick="this.closest('.item-row').remove(); hitungTotal();" class="w-full sm:w-auto bg-red-500 text-white px-3 py-2 rounded text-sm text-center">Hapus</button>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="flex flex-col sm:flex-row gap-2.5 sm:items-center item-row bg-slate-50 sm:bg-transparent p-3 sm:p-0 border sm:border-0 rounded-lg">
                                    <select name="produk_id[]" required class="w-full sm:flex-grow border rounded p-2 text-sm produk-select" onchange="updateHargaDanStok(this)">
                                        <option value="">-- Pilih Produk --</option>
                                        <?php
                                        $prod = $pdo->query("SELECT * FROM produk");
                                        while($p = $prod->fetch()):
                                            $h_ritel = $p['harga_ritel'] ?? 0;
                                            $h_grosir = $p['harga_grosir'] ?? 0;
                                            $h_dist = $p['harga_distributor'] ?? 0;
                                            $h_beli = $p['harga_beli'] ?? 0;
                                        ?>
                                        <option value="<?= $p['id'] ?>" 
                                                data-ritel="<?= $h_ritel ?>" 
                                                data-grosir="<?= $h_grosir ?>" 
                                                data-distributor="<?= $h_dist ?>" 
                                                data-beli="<?= $h_beli ?>" 
                                                data-stok="<?= $p['stok'] ?>">
                                            <?= $p['nama'] ?> (Stok: <?= $p['stok'] ?>)
                                        </option>
                                        <?php endwhile; ?>
                                    </select>
                                    <div class="flex gap-2 w-full sm:w-auto">
                                        <input type="number" name="jumlah[]" placeholder="Qty" value="1" min="1" required class="w-1/2 sm:w-24 border rounded p-2 text-sm qty-input" onchange="hitungTotal()">
                                        <input type="number" step="any" name="harga[]" placeholder="Harga" required class="w-1/2 sm:w-36 border rounded p-2 text-sm harga-input" onchange="hitungTotal()">
                                    </div>
                                    <button type="button" onclick="this.closest('.item-row').remove(); hitungTotal();" class="w-full sm:w-auto bg-red-500 text-white px-3 py-2 rounded text-sm text-center">Hapus</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <button type="button" onclick="addItem()" class="mt-3 bg-slate-200 text-slate-700 px-4 py-2 rounded text-xs md:text-sm font-semibold hover:bg-slate-300 transition">+ Tambah Item</button>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center border-t pt-4 gap-4">
                        <div class="text-base md:text-lg font-bold">Total: <span id="grand_total">Rp 0</span></div>
                        <div class="w-full sm:w-auto">
                            <button type="submit" name="simpan_transaksi" class="w-full sm:w-auto bg-indigo-600 text-white font-bold px-6 py-2.5 rounded hover:bg-indigo-700 transition text-sm">Simpan Perubahan & Update Transaksi</button>
                        </div>
                    </div>
                </form>
            </div>
            <script>
                let currentCustomerTipe = 'ritel';

                window.onload = function() {
                    let partnerSelect = document.getElementById('partner_select');
                    if (partnerSelect && partnerSelect.value) {
                        onPartnerChange(partnerSelect);
                    }
                    hitungTotal();
                };

                function onPartnerChange(el) {
                    let opt = el.options[el.selectedIndex];
                    currentCustomerTipe = opt.getAttribute('data-tipe') || 'ritel';
                }

                function toggleDP() {
                    let tb = document.getElementById('tipe_bayar').value;
                    let dpContainer = document.getElementById('dp_container');
                    if (tb === 'kredit') {
                        dpContainer.classList.remove('hidden');
                    } else {
                        dpContainer.classList.add('hidden');
                        document.getElementById('dp_input').value = 0;
                    }
                }

                function updateHargaDanStok(el) {
                    let opt = el.options[el.selectedIndex];
                    let row = el.closest('.item-row');
                    let hargaInput = row.querySelector('.harga-input');
                    let qtyInput = row.querySelector('.qty-input');
                    let pageType = "<?= $page ?>";

                    if (pageType === 'penjualan') {
                        let stok = parseInt(opt.getAttribute('data-stok')) || 0;
                        qtyInput.setAttribute('max', stok);

                        let harga = 0;
                        if (currentCustomerTipe === 'grosir') {
                            harga = opt.getAttribute('data-grosir') || 0;
                        } else if (currentCustomerTipe === 'distributor') {
                            harga = opt.getAttribute('data-distributor') || 0;
                        } else {
                            harga = opt.getAttribute('data-ritel') || 0;
                        }
                        hargaInput.value = harga;
                    } else {
                        let hargaBeli = opt.getAttribute('data-beli') || 0;
                        hargaInput.value = hargaBeli;
                    }
                    hitungTotal();
                }

                function addItem() {
                    let container = document.getElementById('items-container');
                    let firstRow = container.querySelector('.item-row');
                    let clone = firstRow.cloneNode(true);
                    clone.querySelector('.produk-select').value = '';
                    clone.querySelector('.qty-input').value = 1;
                    clone.querySelector('.harga-input').value = '';
                    container.appendChild(clone);
                    hitungTotal();
                }

                function hitungTotal() {
                    let total = 0;
                    document.querySelectorAll('.item-row').forEach(row => {
                        let qty = parseFloat(row.querySelector('.qty-input').value) || 0;
                        let harga = parseFloat(row.querySelector('.harga-input').value) || 0;
                        total += qty * harga;
                    });
                    document.getElementById('grand_total').innerText = 'Rp ' + total.toLocaleString('id-ID');
                }

                function validateForm(e, pageType) {
                    if (pageType === 'penjualan') {
                        let isValid = true;
                        let errorMessage = "";
                        document.querySelectorAll('.item-row').forEach(row => {
                            let select = row.querySelector('.produk-select');
                            let opt = select.options[select.selectedIndex];
                            let stok = parseInt(opt.getAttribute('data-stok')) || 0;
                            let qty = parseInt(row.querySelector('.qty-input').value) || 0;
                            let namaProduk = opt.text;

                            if (qty > stok) {
                                isValid = false;
                                errorMessage += `Stok tidak cukup untuk produk: ${namaProduk} (Stok tersedia: ${stok})\n`;
                            }
                        });

                        if (!isValid) {
                            alert("PENGISIAN GAGAL:\n" + errorMessage);
                            e.preventDefault();
                            return false;
                        }
                    }
                    return true;
                }
            </script>

        <?php elseif ($page == 'daftar_penjualan' || $page == 'daftar_pembelian'): ?>
            <?php 
            $jenis_trx = ($page == 'daftar_penjualan') ? 'penjualan' : 'pembelian';
            $judul_trx = ($page == 'daftar_penjualan') ? 'Riwayat Transaksi Penjualan' : 'Riwayat Transaksi Pembelian';
            $partner_tbl = ($page == 'daftar_penjualan') ? 'customer' : 'supplier';
            ?>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg md:text-xl font-bold uppercase"><?= $judul_trx ?></h3>
                    <a href="index.php?page=<?= $jenis_trx ?>" class="bg-indigo-600 text-white px-4 py-2 rounded text-sm font-semibold hover:bg-indigo-700 transition">+ Buat <?= ucfirst($jenis_trx) ?></a>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs md:text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th class="p-2.5 md:p-3">Invoice</th>
                                <th class="p-2.5 md:p-3">Tanggal</th>
                                <th class="p-2.5 md:p-3">Partner (<?= ucfirst($partner_tbl) ?>)</th>
                                <th class="p-2.5 md:p-3">Tipe Bayar</th>
                                <th class="p-2.5 md:p-3">Total</th>
                                <th class="p-2.5 md:p-3">Status</th>
                                <th class="p-2.5 md:p-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $st = $pdo->prepare("SELECT t.*, p.nama as partner_nama FROM transaksi t LEFT JOIN $partner_tbl p ON t.partner_id = p.id WHERE t.jenis = ? ORDER BY t.id DESC");
                            $st->execute([$jenis_trx]);
                            $hasData = false;
                            while($row = $st->fetch()):
                                $hasData = true;
                            ?>
                            <tr class="border-b">
                                <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['no_invoice'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['tanggal'] ?></td>
                                <td class="p-2.5 md:p-3"><?= $row['partner_nama'] ?? 'Umum' ?></td>
                                <td class="p-2.5 md:p-3 uppercase text-xs whitespace-nowrap"><?= $row['tipe_bayar'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['total'], 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><span class="px-2 py-0.5 rounded text-xs <?= $row['status']=='lunas'?'bg-emerald-100 text-emerald-800':'bg-amber-100 text-amber-800' ?>"><?= strtoupper($row['status']) ?></span></td>
                                <td class="p-2.5 md:p-3 space-x-2 whitespace-nowrap">
                                    <a href="index.php?page=invoice&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold hover:underline">Invoice</a>
                                    <a href="index.php?page=<?= $jenis_trx ?>&action=edit&id=<?= $row['id'] ?>" class="text-blue-600 font-semibold hover:underline">Edit</a>
                                    <a href="index.php?page=<?= $page ?>&action=delete&id=<?= $row['id'] ?>" onclick="return confirm('Hapus transaksi ini? Stok produk akan dikembalikan secara otomatis.')" class="text-red-600 hover:underline font-semibold">Hapus</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if(!$hasData): ?>
                            <tr>
                                <td colspan="7" class="p-4 text-center text-slate-500">Belum ada data transaksi <?= $jenis_trx ?>.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($page == 'cicilan'): ?>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200 mb-8">
                <h3 class="text-lg md:text-xl font-bold mb-4">Form Pembayaran Cicilan Kredit</h3>
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end" onsubmit="return validateCicilan(event)">
                    <div>
                        <label class="block text-xs md:text-sm font-semibold mb-1">Pilih Transaksi Kredit Belum Lunas</label>
                        <select name="transaksi_id" id="transaksi_pilih" required class="w-full border rounded p-2 text-sm" onchange="updateMaxCicilan(this)">
                            <option value="">-- Pilih Invoice Kredit --</option>
                            <?php
                            $st = $pdo->query("SELECT t.*, 
                                COALESCE(c.nama, s.nama, 'Umum') as partner_nama 
                                FROM transaksi t 
                                LEFT JOIN customer c ON t.partner_id = c.id AND t.jenis='penjualan' 
                                LEFT JOIN supplier s ON t.partner_id = s.id AND t.jenis='pembelian' 
                                WHERE t.tipe_bayar='kredit' AND t.status='belum_lunas' 
                                ORDER BY t.id DESC");
                            while($r = $st->fetch()):
                            ?>
                            <option value="<?= $r['id'] ?>" data-sisa="<?= $r['sisa'] ?>"><?= $r['no_invoice'] ?> (<?= strtoupper($r['jenis']) ?>) - <?= $r['partner_nama'] ?> (Sisa: Rp <?= number_format($r['sisa'], 0, ',', '.') ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs md:text-sm font-semibold mb-1">Jumlah Bayar Cicilan</label>
                        <input type="number" step="any" name="jumlah_bayar" id="jumlah_bayar_input" required class="w-full border rounded p-2 text-sm">
                        <small id="max_info" class="text-xs text-slate-500 mt-1 block"></small>
                    </div>
                    <div>
                        <button type="submit" name="bayar_cicilan" class="w-full bg-indigo-600 text-white font-bold py-2.5 rounded hover:bg-indigo-700 transition text-sm">Bayar & Cetak PDF / Faktur</button>
                    </div>
                </form>
            </div>

            <script>
            let currentSisaHutang = 0;
            function updateMaxCicilan(el) {
                let opt = el.options[el.selectedIndex];
                currentSisaHutang = parseFloat(opt.getAttribute('data-sisa')) || 0;
                let inputBayar = document.getElementById('jumlah_bayar_input');
                inputBayar.setAttribute('max', currentSisaHutang);
                document.getElementById('max_info').innerText = "Maksimal pembayaran: Rp " + currentSisaHutang.toLocaleString('id-ID');
            }
            function validateCicilan(e) {
                let bayar = parseFloat(document.getElementById('jumlah_bayar_input').value) || 0;
                if (bayar > currentSisaHutang) {
                    alert("Jumlah pembayaran cicilan tidak boleh melebihi sisa hutang (Rp " + currentSisaHutang.toLocaleString('id-ID') + ")!");
                    e.preventDefault();
                    return false;
                }
                return true;
            }
            </script>

            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <h3 class="text-base md:text-lg font-bold mb-4">Riwayat Cicilan Terbaru</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs md:text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th class="p-2.5 md:p-3">Invoice</th>
                                <th class="p-2.5 md:p-3">Tanggal Bayar</th>
                                <th class="p-2.5 md:p-3">Jumlah Bayar</th>
                                <th class="p-2.5 md:p-3">Sisa Hutang Sesudah</th>
                                <th class="p-2.5 md:p-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $st = $pdo->query("SELECT c.*, t.no_invoice FROM cicilan c JOIN transaksi t ON c.transaksi_id = t.id ORDER BY c.id DESC");
                            $hasCicilan = false;
                            while($row = $st->fetch()):
                                $hasCicilan = true;
                            ?>
                            <tr class="border-b">
                                <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $row['no_invoice'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $row['tanggal_bayar'] ?></td>
                                <td class="p-2.5 md:p-3 text-green-600 font-bold whitespace-nowrap">Rp <?= number_format($row['jumlah_bayar'], 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap">Rp <?= number_format($row['sisa_sesudah'], 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><a href="index.php?page=invoice_cicilan&trx_id=<?= $row['transaksi_id'] ?>" class="text-indigo-600 hover:underline font-semibold">Cetak Faktur</a></td>
                            </tr>
                            <?php endwhile; ?>
                            <?php if(!$hasCicilan): ?>
                            <tr>
                                <td colspan="5" class="p-4 text-center text-slate-500">Belum ada riwayat pembayaran cicilan.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($page == 'invoice' && $id): ?>
            <?php
            $st = $pdo->prepare("SELECT t.*, c.nama as cust_nama, c.alamat as cust_alamat, c.telepon as cust_telp, s.nama as supp_nama, s.perusahaan as supp_per, s.alamat as supp_alamat, s.telepon as supp_telp FROM transaksi t LEFT JOIN customer c ON t.partner_id = c.id AND t.jenis='penjualan' LEFT JOIN supplier s ON t.partner_id = s.id AND t.jenis='pembelian' WHERE t.id = ?");
            $st->execute([$id]);
            $trx = $st->fetch();
            if (!$trx) die("Transaksi tidak ditemukan.");
            
            $dt = $pdo->prepare("SELECT td.*, p.nama as produk_nama FROM transaksi_detail td JOIN produk p ON td.produk_id = p.id WHERE td.transaksi_id = ?");
            $dt->execute([$id]);
            $details = $dt->fetchAll();

            $partner_nama = ($trx['jenis'] == 'penjualan') ? $trx['cust_nama'] : $trx['supp_nama'];
            $label_penerima = ($trx['jenis'] == 'penjualan') ? 'Penerima / Customer' : 'Pemberi / Supplier';
            $back_list_page = ($trx['jenis'] == 'penjualan') ? 'daftar_penjualan' : 'daftar_pembelian';
            ?>
            <div class="max-w-3xl mx-auto bg-white p-4 md:p-8 rounded-xl shadow border border-slate-200">
                <div id="printable-area" class="bg-white p-2 md:p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start border-b pb-4 mb-4 gap-2">
                        <div>
                            <h2 class="text-xl md:text-2xl font-black text-indigo-900">RAYIRAKA - 165</h2>
                            <p class="text-[10px] md:text-xs text-slate-500 uppercase">Produsen Alat Pancing Sukabumi</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <h3 class="text-base md:text-lg font-bold uppercase">FAKTUR <?= $trx['jenis'] ?></h3>
                            <p class="text-xs md:text-sm text-slate-600"><?= $trx['no_invoice'] ?></p>
                            <p class="text-[10px] md:text-xs text-slate-500"><?= $trx['tanggal'] ?></p>
                        </div>
                    </div>
                    
                    <div class="mb-6 text-xs md:text-sm">
                        <p class="font-semibold text-slate-700">Kepada / Partner:</p>
                        <p class="font-bold text-sm md:text-base"><?= $trx['jenis']=='penjualan' ? $trx['cust_nama'] : $trx['supp_nama'] . ' (' . $trx['supp_per'] . ')' ?></p>
                        <p><?= $trx['jenis']=='penjualan' ? $trx['cust_alamat'] : $trx['supp_alamat'] ?></p>
                    </div>

                    <div class="overflow-x-auto mb-6">
                        <table class="w-full text-left text-xs md:text-sm border-collapse">
                            <thead>
                                <tr class="bg-slate-100 border-b">
                                    <th class="p-2">Produk</th>
                                    <th class="p-2 text-center">Qty</th>
                                    <th class="p-2 text-right">Harga</th>
                                    <th class="p-2 text-right">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach($details as $d): ?>
                                <tr class="border-b">
                                    <td class="p-2 whitespace-nowrap"><?= $d['produk_nama'] ?></td>
                                    <td class="p-2 text-center whitespace-nowrap"><?= $d['jumlah'] ?></td>
                                    <td class="p-2 text-right whitespace-nowrap">Rp <?= number_format($d['harga'], 0, ',', '.') ?></td>
                                    <td class="p-2 text-right whitespace-nowrap">Rp <?= number_format($d['subtotal'], 0, ',', '.') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex justify-end mb-8">
                        <div class="w-full sm:w-64 space-y-1 text-xs md:text-sm">
                            <div class="flex justify-between font-bold text-sm md:text-base border-t pt-2">
                                <span>Total:</span>
                                <span>Rp <?= number_format($trx['total'], 0, ',', '.') ?></span>
                            </div>
                            <div class="flex justify-between text-slate-600">
                                <span>Tipe Bayar:</span>
                                <span class="uppercase font-semibold"><?= $trx['tipe_bayar'] ?></span>
                            </div>
                            <?php if($trx['tipe_bayar']=='kredit'): ?>
                            <div class="flex justify-between text-slate-600">
                                <span>DP (Deposit):</span>
                                <span>Rp <?= number_format($trx['dp'], 0, ',', '.') ?></span>
                            </div>
                            <div class="flex justify-between text-red-600 font-semibold">
                                <span>Sisa Kurang:</span>
                                <span>Rp <?= number_format($trx['sisa'], 0, ',', '.') ?></span>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between text-slate-600">
                                <span>Status:</span>
                                <span class="uppercase font-bold"><?= $trx['status'] ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Format Tanda Tangan -->
                    <div class="flex flex-col sm:flex-row justify-between items-center sm:items-start text-xs md:text-sm mt-12 pt-4 gap-8">
                        <div class="text-center w-full sm:w-48">
                            <p class="font-semibold"><?= $label_penerima ?></p>
                            <div class="h-12 sm:h-16"></div>
                            <p class="font-semibold">( . . . . . . . . . . . . . . . . . . . )</p>
                            <p class="text-[11px] text-slate-600 mt-1"><?= $partner_nama ?></p>
                        </div>
                        <div class="text-center w-full sm:w-48">
                            <p class="font-semibold">Hormat Kami,</p>
                            <p class="font-semibold">RAYIRAKA - 165 Sukabumi</p>
                            <div class="h-8 sm:h-12"></div>
                            <p class="font-semibold">( Bagian Keuangan )</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center no-print border-t pt-4 mt-6 gap-2">
                    <a href="index.php?page=<?= $back_list_page ?>" class="bg-slate-200 px-4 py-2 rounded text-xs md:text-sm font-semibold">Kembali ke Riwayat</a>
                    <button onclick="window.print()" class="bg-indigo-600 text-white font-bold px-4 md:px-6 py-2 rounded hover:bg-indigo-700 transition text-xs md:text-sm">Cetak / Simpan PDF</button>
                </div>
            </div>

        <?php elseif ($page == 'invoice_cicilan' && isset($_GET['trx_id'])): ?>
            <?php
            $trx_id = $_GET['trx_id'];
            $st = $pdo->prepare("SELECT t.*, c.nama as cust_nama, c.alamat as cust_alamat, s.nama as supp_nama, s.alamat as supp_alamat FROM transaksi t LEFT JOIN customer c ON t.partner_id = c.id AND t.jenis='penjualan' LEFT JOIN supplier s ON t.partner_id = s.id AND t.jenis='pembelian' WHERE t.id = ?");
            $st->execute([$trx_id]);
            $trx = $st->fetch();
            
            $cc = $pdo->prepare("SELECT * FROM cicilan WHERE transaksi_id = ? ORDER BY id DESC");
            $cc->execute([$trx_id]);
            $cicilans = $cc->fetchAll();
            $last_cicilan = $cicilans[0] ?? null;
            
            $partner_nama = ($trx['jenis'] == 'penjualan') ? $trx['cust_nama'] : $trx['supp_nama'];
            $partner_alamat = ($trx['jenis'] == 'penjualan') ? $trx['cust_alamat'] : $trx['supp_alamat'];
            $label_penerima = ($trx['jenis'] == 'penjualan') ? 'Penerima / Customer' : 'Pemberi / Supplier';
            ?>
            <div class="max-w-3xl mx-auto bg-white p-4 md:p-8 rounded-xl shadow border border-slate-200">
                <div id="printable-area" class="bg-white p-2 md:p-6">
                    <div class="flex flex-col sm:flex-row justify-between items-start border-b pb-4 mb-4 gap-2">
                        <div>
                            <h2 class="text-xl md:text-2xl font-black text-indigo-900">RAYIRAKA - 165</h2>
                            <p class="text-[10px] md:text-xs text-slate-500 uppercase">Produsen Alat Pancing Sukabumi</p>
                        </div>
                        <div class="text-left sm:text-right">
                            <h3 class="text-base md:text-lg font-bold uppercase">BUKTI PEMBAYARAN CICILAN</h3>
                            <p class="text-xs md:text-sm text-slate-600">Ref: <?= $trx['no_invoice'] ?></p>
                            <p class="text-[10px] md:text-xs text-slate-500"><?= $last_cicilan['tanggal_bayar'] ?? '' ?></p>
                        </div>
                    </div>
                    
                    <div class="mb-6 text-xs md:text-sm">
                        <p class="font-semibold text-slate-700">Partner (<?= ucfirst($trx['jenis']) ?>):</p>
                        <p class="font-bold text-sm md:text-base"><?= $partner_nama ?></p>
                        <p><?= $partner_alamat ?></p>
                    </div>

                    <div class="bg-indigo-50 p-3 md:p-4 rounded mb-6 text-xs md:text-sm">
                        <p class="font-bold text-indigo-900 mb-1">Rincian Pembayaran Cicilan Terbaru:</p>
                        <p>Jumlah Dibayarkan: <span class="font-bold text-green-700">Rp <?= number_format($last_cicilan['jumlah_bayar'] ?? 0, 0, ',', '.') ?></span></p>
                        <p>Sisa Hutang Sekarang: <span class="font-bold text-red-600">Rp <?= number_format($last_cicilan['sisa_sesudah'] ?? 0, 0, ',', '.') ?></span></p>
                        <p>Status Transaksi: <span class="uppercase font-bold"><?= $trx['status'] ?></span></p>
                    </div>

                    <h4 class="font-bold text-xs md:text-sm mb-2">Riwayat Semua Cicilan</h4>
                    <div class="overflow-x-auto mb-8">
                        <table class="w-full text-left text-xs md:text-sm border-collapse">
                            <thead>
                                <tr class="bg-slate-100 border-b">
                                    <th class="p-2">No</th>
                                    <th class="p-2">Tanggal Bayar</th>
                                    <th class="p-2 text-right">Jumlah Bayar</th>
                                    <th class="p-2 text-right">Sisa Sesudah</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no=1; foreach($cicilans as $c): ?>
                                <tr class="border-b">
                                    <td class="p-2 whitespace-nowrap"><?= $no++ ?></td>
                                    <td class="p-2 whitespace-nowrap"><?= $c['tanggal_bayar'] ?></td>
                                    <td class="p-2 text-right whitespace-nowrap">Rp <?= number_format($c['jumlah_bayar'], 0, ',', '.') ?></td>
                                    <td class="p-2 text-right whitespace-nowrap">Rp <?= number_format($c['sisa_sesudah'], 0, ',', '.') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Format Tanda Tangan -->
                    <div class="flex flex-col sm:flex-row justify-between items-center sm:items-start text-xs md:text-sm mt-12 pt-4 gap-8">
                        <div class="text-center w-full sm:w-48">
                            <p class="font-semibold"><?= $label_penerima ?></p>
                            <div class="h-12 sm:h-16"></div>
                            <p class="font-semibold">( . . . . . . . . . . . . . . . . . . . )</p>
                            <p class="text-[11px] text-slate-600 mt-1"><?= $partner_nama ?></p>
                        </div>
                        <div class="text-center w-full sm:w-48">
                            <p class="font-semibold">Hormat Kami,</p>
                            <p class="font-semibold">RAYIRAKA - 165 Sukabumi</p>
                            <div class="h-8 sm:h-12"></div>
                            <p class="font-semibold">( Bagian Keuangan )</p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center no-print border-t pt-4 mt-6 gap-2">
                    <a href="index.php?page=cicilan" class="bg-slate-200 px-4 py-2 rounded text-xs md:text-sm font-semibold">Kembali</a>
                    <button onclick="window.print()" class="bg-indigo-600 text-white font-bold px-4 md:px-6 py-2 rounded hover:bg-indigo-700 transition text-xs md:text-sm">Cetak / Simpan PDF</button>
                </div>
            </div>

        <?php elseif ($page == 'laporan'): ?>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <h3 class="text-lg md:text-xl font-bold mb-6">Laporan Laba / Rugi Kotor</h3>
                
                <?php
                $filter_mode = $_GET['filter_mode'] ?? 'semua';
                $tgl_harian = $_GET['tgl_harian'] ?? date('Y-m-d');
                $bln_bulanan = $_GET['bln_bulanan'] ?? date('m');
                $thn_bulanan = $_GET['thn_bulanan'] ?? date('Y');
                $thn_tahunan = $_GET['thn_tahunan'] ?? date('Y');

                $where_sql = "WHERE t.jenis = 'penjualan'";
                $params = [];

                if ($filter_mode == 'harian') {
                    $where_sql .= " AND DATE(t.tanggal) = ?";
                    $params[] = $tgl_harian;
                } elseif ($filter_mode == 'bulanan') {
                    $where_sql .= " AND MONTH(t.tanggal) = ? AND YEAR(t.tanggal) = ?";
                    $params[] = $bln_bulanan;
                    $params[] = $thn_bulanan;
                } elseif ($filter_mode == 'tahunan') {
                    $where_sql .= " AND YEAR(t.tanggal) = ?";
                    $params[] = $thn_tahunan;
                }

                $sql = "SELECT t.no_invoice, t.tanggal, td.jumlah, td.harga as harga_jual, p.harga_beli 
                        FROM transaksi_detail td 
                        JOIN transaksi t ON td.transaksi_id = t.id 
                        JOIN produk p ON td.produk_id = p.id 
                        $where_sql 
                        ORDER BY t.tanggal DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $res = $stmt->fetchAll();
                ?>

                <form method="GET" action="index.php" class="bg-slate-50 border p-3 md:p-4 rounded-xl mb-6 flex flex-wrap gap-3 items-end">
                    <input type="hidden" name="page" value="laporan">
                    <div>
                        <label class="block text-xs font-semibold uppercase mb-1">Filter Waktu</label>
                        <select name="filter_mode" id="filter_mode" onchange="toggleFilterFields()" class="border rounded p-2 text-sm bg-white">
                            <option value="semua" <?= $filter_mode=='semua'?'selected':'' ?>>Semua Waktu</option>
                            <option value="harian" <?= $filter_mode=='harian'?'selected':'' ?>>Per Hari (Harian)</option>
                            <option value="bulanan" <?= $filter_mode=='bulanan'?'selected':'' ?>>Per Bulan (Bulanan)</option>
                            <option value="tahunan" <?= $filter_mode=='tahunan'?'selected':'' ?>>Per Tahun (Tahunan)</option>
                        </select>
                    </div>
                    <div id="field_harian" class="<?= $filter_mode=='harian'?'':'hidden' ?>">
                        <label class="block text-xs font-semibold uppercase mb-1">Tanggal</label>
                        <input type="date" name="tgl_harian" value="<?= $tgl_harian ?>" class="border rounded p-2 text-sm bg-white">
                    </div>
                    <div id="field_bulanan" class="flex gap-2 <?= $filter_mode=='bulanan'?'':'hidden' ?>">
                        <div>
                            <label class="block text-xs font-semibold uppercase mb-1">Bulan</label>
                            <select name="bln_bulanan" class="border rounded p-2 text-sm bg-white">
                                <?php for($m=1; $m<=12; $m++): $m_val = str_pad($m, 2, '0', STR_PAD_LEFT); ?>
                                <option value="<?= $m_val ?>" <?= $bln_bulanan==$m_val?'selected':'' ?>><-- <?= date('F', mktime(0,0,0,$m,10)) ?> --></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase mb-1">Tahun</label>
                            <input type="number" name="thn_bulanan" value="<?= $thn_bulanan ?>" class="w-24 border rounded p-2 text-sm bg-white">
                        </div>
                    </div>
                    <div id="field_tahunan" class="<?= $filter_mode=='tahunan'?'':'hidden' ?>">
                        <label class="block text-xs font-semibold uppercase mb-1">Tahun</label>
                        <input type="number" name="thn_tahunan" value="<?= $thn_tahunan ?>" class="w-28 border rounded p-2 text-sm bg-white">
                    </div>
                    <div>
                        <button type="submit" class="bg-indigo-600 text-white font-semibold px-4 py-2 rounded text-sm hover:bg-indigo-700 transition">Tampilkan</button>
                    </div>
                </form>

                <script>
                function toggleFilterFields() {
                    let mode = document.getElementById('filter_mode').value;
                    document.getElementById('field_harian').classList.add('hidden');
                    document.getElementById('field_bulanan').classList.add('hidden');
                    document.getElementById('field_tahunan').classList.add('hidden');
                    if (mode === 'harian') document.getElementById('field_harian').classList.remove('hidden');
                    if (mode === 'bulanan') document.getElementById('field_bulanan').classList.remove('hidden');
                    if (mode === 'tahunan') document.getElementById('field_tahunan').classList.remove('hidden');
                }
                </script>

                <div class="overflow-x-auto mb-6">
                    <table class="w-full text-left text-xs md:text-sm">
                        <thead class="bg-slate-100 text-slate-700">
                            <tr>
                                <th class="p-2.5 md:p-3">Invoice</th>
                                <th class="p-2.5 md:p-3">Tanggal</th>
                                <th class="p-2.5 md:p-3 text-center">Qty</th>
                                <th class="p-2.5 md:p-3 text-right">Total Jual</th>
                                <th class="p-2.5 md:p-3 text-right">Total HPP</th>
                                <th class="p-2.5 md:p-3 text-right">Laba Kotor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $tot_penjualan = 0;
                            $tot_hpp = 0;
                            if(empty($res)):
                            ?>
                            <tr>
                                <td colspan="6" class="p-4 text-center text-slate-500">Tidak ada data transaksi penjualan pada periode ini.</td>
                            </tr>
                            <?php 
                            else:
                                foreach($res as $r): 
                                    $jual = $r['jumlah'] * $r['harga_jual'];
                                    $hpp = $r['jumlah'] * $r['harga_beli'];
                                    $laba = $jual - $hpp;
                                    $tot_penjualan += $jual;
                                    $tot_hpp += $hpp;
                            ?>
                            <tr class="border-b">
                                <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $r['no_invoice'] ?></td>
                                <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $r['tanggal'] ?></td>
                                <td class="p-2.5 md:p-3 text-center whitespace-nowrap"><?= $r['jumlah'] ?></td>
                                <td class="p-2.5 md:p-3 text-right whitespace-nowrap">Rp <?= number_format($jual, 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 text-right whitespace-nowrap">Rp <?= number_format($hpp, 0, ',', '.') ?></td>
                                <td class="p-2.5 md:p-3 text-right font-bold text-green-700 whitespace-nowrap">Rp <?= number_format($laba, 0, ',', '.') ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php $total_laba = $tot_penjualan - $tot_hpp; ?>
                <div class="bg-slate-100 p-4 md:p-6 rounded-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                    <div>
                        <p class="text-xs md:text-sm text-slate-600">Total Penjualan Periode Ini: <span class="font-bold text-slate-800">Rp <?= number_format($tot_penjualan, 0, ',', '.') ?></span></p>
                        <p class="text-xs md:text-sm text-slate-600">Total HPP Periode Ini: <span class="font-bold text-slate-800">Rp <?= number_format($tot_hpp, 0, ',', '.') ?></span></p>
                    </div>
                    <div class="text-left md:text-right">
                        <p class="text-[10px] md:text-xs uppercase font-semibold text-slate-500">Total Laba Kotor Periode Ini</p>
                        <p class="text-xl md:text-2xl font-black text-indigo-900">Rp <?= number_format($total_laba, 0, ',', '.') ?></p>
                    </div>
                </div>
            </div>

        <?php elseif ($page == 'laporan_stok'): ?>
            <div class="bg-white p-4 md:p-6 rounded-xl shadow border border-slate-200">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4 no-print">
                    <h3 class="text-lg md:text-xl font-bold uppercase">Laporan Stok & Nilai Persediaan Barang</h3>
                    <button onclick="window.print()" class="bg-indigo-600 text-white font-bold px-4 py-2 rounded hover:bg-indigo-700 transition text-sm">Cetak / Simpan PDF</button>
                </div>

                <div id="printable-area">
                    <div class="hidden print:block mb-6 text-center">
                        <h2 class="text-2xl font-black text-indigo-900">RAYIRAKA - 165</h2>
                        <p class="text-xs text-slate-500 uppercase">Produsen Alat Pancing Sukabumi</p>
                        <h3 class="text-lg font-bold uppercase mt-2">Laporan Stok & Nilai Persediaan Barang</h3>
                        <p class="text-xs text-slate-500">Per Tanggal: <?= date('d-m-Y H:i') ?></p>
                    </div>

                    <div class="overflow-x-auto mb-6">
                        <table class="w-full text-left text-xs md:text-sm border-collapse">
                            <thead class="bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="p-2.5 md:p-3">No</th>
                                    <th class="p-2.5 md:p-3">Kode</th>
                                    <th class="p-2.5 md:p-3">Nama Produk</th>
                                    <th class="p-2.5 md:p-3 text-right">Harga Beli (HPP)</th>
                                    <th class="p-2.5 md:p-3 text-center">Stok</th>
                                    <th class="p-2.5 md:p-3 text-right">Total Nilai Persediaan (HPP * Stok)</th>
                                    <th class="p-2.5 md:p-3 text-center">Status Stok</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $stok_query = $pdo->query("SELECT * FROM produk ORDER BY nama ASC");
                                $no = 1;
                                $total_item_stok = 0;
                                $total_nilai_aset = 0;
                                while($p = $stok_query->fetch()):
                                    $nilai_persediaan = $p['stok'] * $p['harga_beli'];
                                    $total_item_stok += $p['stok'];
                                    $total_nilai_aset += $nilai_persediaan;
                                    
                                    if ($p['stok'] <= 0) {
                                        $status_badge = '<span class="px-2 py-0.5 rounded text-xs bg-red-100 text-red-800 font-bold">Habis</span>';
                                    } elseif ($p['stok'] <= 5) {
                                        $status_badge = '<span class="px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-800 font-bold">Menipis</span>';
                                    } else {
                                        $status_badge = '<span class="px-2 py-0.5 rounded text-xs bg-emerald-100 text-emerald-800 font-bold">Aman</span>';
                                    }
                                ?>
                                <tr class="border-b">
                                    <td class="p-2.5 md:p-3 whitespace-nowrap"><?= $no++ ?></td>
                                    <td class="p-2.5 md:p-3 font-semibold whitespace-nowrap"><?= $p['kode'] ?></td>
                                    <td class="p-2.5 md:p-3"><?= $p['nama'] ?></td>
                                    <td class="p-2.5 md:p-3 text-right whitespace-nowrap">Rp <?= number_format($p['harga_beli'], 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 text-center font-bold whitespace-nowrap <?= $p['stok'] <= 0 ? 'text-red-600' : ($p['stok'] <= 5 ? 'text-amber-600' : '') ?>"><?= $p['stok'] ?></td>
                                    <td class="p-2.5 md:p-3 text-right whitespace-nowrap">Rp <?= number_format($nilai_persediaan, 0, ',', '.') ?></td>
                                    <td class="p-2.5 md:p-3 text-center whitespace-nowrap"><?= $status_badge ?></td>
                                </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-slate-100 p-4 md:p-6 rounded-xl flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <p class="text-xs md:text-sm text-slate-600">Total Jenis Produk: <span class="font-bold text-slate-800"><?= ($no - 1) ?> Item</span></p>
                            <p class="text-xs md:text-sm text-slate-600">Total Kuantitas Fisik Stok: <span class="font-bold text-slate-800"><?= number_format($total_item_stok, 0, ',', '.') ?> Unit</span></p>
                        </div>
                        <div class="text-left md:text-right">
                            <p class="text-[10px] md:text-xs uppercase font-semibold text-slate-500">Total Nilai Aset Persediaan (HPP)</p>
                            <p class="text-xl md:text-2xl font-black text-indigo-900">Rp <?= number_format($total_nilai_aset, 0, ',', '.') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-slate-200 mt-12 py-4 text-center text-xs text-slate-500 px-4">
        &copy; <?= date('Y') ?> RAYIRAKA - 165 - Produsen Alat Pancing Sukabumi. All rights reserved.
    </footer>
    
</body>
</html>