<p align="center">
  <img src=".github/assets/banner.svg" alt="Klaras Inven, plugin SLiMS: catat barang per ruangan, periksa ruangan, dan stock opname lewat aplikasi InvenSync" width="100%">
</p>

<p align="center">
  <a href="https://github.com/klarasid/inven/releases/latest"><img src="https://img.shields.io/github/v/release/klarasid/inven?label=rilis&color=006B60" alt="Rilis terbaru"></a>
  <img src="https://img.shields.io/badge/SLiMS-9-006B60" alt="SLiMS 9">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-006B60" alt="PHP 7.4 atau lebih baru">
</p>

Klaras Inven adalah plugin SLiMS 9 untuk mengelola sarana prasarana perpustakaan. Dengan plugin ini, Anda dapat:

- Mencatat barang per ruangan, lengkap dengan foto, dan mencetak Kartu Inventaris Ruangan (KIR).
- Menjadwalkan pemeriksaan ruangan berdasarkan checklist, lalu menindaklanjuti temuannya hingga diverifikasi.
- Mencetak label barang dengan QR code.
- Mencetak laporan PDF dengan kop institusi Anda.
- Memakai aplikasi HP **Klaras InvenSync** (opsional) untuk pendataan dan stock opname di lapangan.

## Sebelum memulai

Pastikan server Anda memenuhi syarat berikut:

- SLiMS 9 dan PHP 7.4 atau lebih baru.
- Ekstensi PHP `gd`, `mbstring`, `fileinfo`, `curl`, `zip`, dan `SimpleXML`.
- Folder cache SLiMS (`files/cache`) dan folder `images` dapat ditulis oleh PHP.

## Memasang plugin

1. Unduh `klaras-inven-<versi>.zip` dari halaman [Releases](https://github.com/klarasid/inven/releases/latest).
2. Ekstrak ke folder `plugins/` di instalasi SLiMS, sehingga terbentuk `plugins/inventaris-barang`.
3. Masuk sebagai administrator, buka **System → Plugins**, lalu aktifkan **Klaras Inven**.
4. Buka **Stock Take → Ruangan & Barang**.

Paket rilis sudah berisi dependensi PHP dan aset yang telah dibangun, sehingga Anda tidak memerlukan Composer atau Node.js.

> [!NOTE]
> Jika Anda memasang dari kode sumber (bukan paket rilis), jalankan `composer install --no-dev` dari folder plugin.

## Memperbarui plugin

1. Cadangkan database serta folder `images/inventaris-barang`.
2. Ekstrak paket rilis terbaru dan timpa folder `plugins/inventaris-barang`.
3. Buka **System → Plugins** dan jalankan migrasi yang tersedia.

Fungsi ruang yang dulu dicentang di form ruangan kini dicatat sebagai **area** di halaman tiap ruangan (tab **Area**). Migrasi 12 memindahkan tiap fungsi yang sudah dicentang menjadi satu area, jadi Rekap Sarpras tidak berubah setelah migrasi dijalankan.

Data **Gedung & Jaringan** yang diisi sebelum plugin membedakan lokasi menjadi milik lokasi dengan ruangan terbanyak. Periksa dan pindahkan bila perlu.

Menu **Perangkat Lunak**, **Gedung & Jaringan**, dan **Pengaturan Cetak** dulu berada di dalam Rekap Sarpras dan Laporan. Migrasi memberikan menu-menu itu kepada grup pengguna yang sudah boleh membuka halaman asalnya; petugas melihatnya setelah masuk ulang. Untuk mengaturnya sendiri, buka **System → User Group**.

Plugin memeriksa rilis baru di GitHub setiap 12 jam. Jika tersedia, pengguna dengan hak tulis melihat pemberitahuan **Versi X tersedia** beserta catatan rilisnya.

> [!IMPORTANT]
> Beberapa migrasi tidak dapat dibatalkan karena menyimpan bukti historis. Selalu cadangkan database sebelum menjalankan migrasi.

## Mengamankan folder foto dan folder plugin

Foto barang, bukti pemeriksaan, template kop, denah ruangan, dan bukti pengukuran bandwidth disimpan di `images/inventaris-barang` dan hanya dapat dibuka melalui panel admin. Plugin membuat `.htaccess` di folder itu dan di tiap subfoldernya untuk memblokir akses langsung di Apache (memerlukan `AllowOverride`).

Berkas PHP plugin dijalankan oleh SLiMS, bukan lewat alamatnya sendiri. `.htaccess` di folder plugin menolak permintaan langsung ke berkas PHP di dalamnya; hanya folder `assets` yang perlu dibuka browser.

Nginx dan Caddy tidak membaca `.htaccess`. Jika Anda memakai Nginx, tambahkan aturan berikut, lalu muat ulang konfigurasinya. Sesuaikan awalan `/opac` dengan path instalasi Anda.

```nginx
location ^~ /opac/images/inventaris-barang/ {
    deny all;
}
location ^~ /opac/plugins/inventaris-barang/ {
    location ~ \.(php|phar)$ { deny all; }
    location ~ /composer\.(json|lock)$ { deny all; }
}
```

Untuk memastikan aturan bekerja, buka URL langsung salah satu foto dan `plugins/inventaris-barang/index.php`. Server seharusnya menjawab **403 Forbidden** untuk keduanya.

Agar lima foto berukuran 2 MB dapat diunggah sekaligus, atur `upload_max_filesize` ke minimal `2M`, `post_max_size` ke minimal `12M`, dan `max_file_uploads` ke minimal `5`.

## Fitur

Semua menu tersedia di modul **Stock Take**, dalam tiga bagian. Pengguna dengan hak baca dapat melihat data dan mencetak PDF; perubahan data memerlukan hak tulis.

**Klaras Inven**: pekerjaan sehari-hari dan ikhtisarnya.

| Menu | Kegunaan |
| --- | --- |
| **Rekap Sarpras** | Melihat kondisi sarana dan prasarana (luas ruang dan area di dalamnya, kondisi barang, perabot, komputer, jaringan, multimedia, lisensi perangkat lunak, keamanan, fasilitas umum, serta pengawasan) dan mencetak rekapnya. Halaman ini hanya ikhtisar; datanya diisi di bagian Data Sarpras. |
| **Tugas** | Mengisi pemeriksaan, melapor kerusakan, mencatat perbaikan, dan memverifikasi hasilnya. Anda juga dapat mengimpor riwayat lama dari Excel. |
| **Jadwal** | Menjadwalkan pemeriksaan ruangan, harian hingga tahunan. Jadwal mengikuti hari libur di **System → Hari Libur**. |
| **Checklist** | Menyusun template checklist pemeriksaan beserta riwayat versinya. |
| **Laporan** | Melihat cakupan dan keterlambatan pemeriksaan, serta mencetak laporan periode atau dokumen pemeriksaan dalam gaya LaTeX, ISO, atau kop institusi. |

**Data Sarpras**: data yang menjadi dasar rekap dan pemeriksaan.

| Menu | Kegunaan |
| --- | --- |
| **Ruangan & Barang** | Mencatat ruangan dan barang, mengunggah hingga 5 foto per barang, membuat kode barang otomatis (misalnya `P01-INV-000001`), dan mencetak KIR. Halaman tiap ruangan memiliki tab **Area** (area apa saja di dalam ruangan) dan **Denah** (gambar atau PDF denah, beberapa per ruangan). |
| **Perangkat Lunak** | Mencatat aplikasi yang dipakai perpustakaan beserta jenis dan masa berlaku lisensinya. |
| **Gedung & Jaringan** | Mengisi luas gedung dan bandwidth internet beserta bukti pengukurannya, untuk tiap lokasi perpustakaan. |
| **Sivitas per Lokasi** | Menghitung sivitas dari anggota SLiMS yang aktif, memetakannya ke lokasi perpustakaan, dan merapikan isian Institusi anggota yang salah ketik. |

**Pengaturan Inven**

| Menu | Kegunaan |
| --- | --- |
| **Pengaturan Cetak** | Mengelola template kop institusi dan nomor dokumen yang tercetak di PDF. |
| **Aplikasi InvenSync** | Mengizinkan aplikasi HP dan mengelola perangkat yang masuk. |

### Rekap Sarpras

Rekap dihitung dari data yang sudah ada, jadi lengkapi dulu klasifikasinya:

1. Di **Ruangan & Barang**, ubah tiap ruangan dan isi **Luas (m²)**. Lalu buka ruangannya dan catat areanya di tab **Area**. Ada empat area layanan dasar (koleksi, baca, kerja staf, layanan), area pendukung, dan fasilitas umum. Toilet, musala, parkir, kantin, dan ruang laktasi dicatat sebagai ruangan dengan area berjenis itu, bukan sebagai barang.
2. Beri **Kategori** dan **Jenis** pada barang (perabot, peralatan, komputer, multimedia, keamanan, fasilitas umum). Satu barang boleh memiliki lebih dari satu kategori dan dihitung di tiap kategorinya, misalnya komputer yang juga perangkat multimedia. Centang beberapa barang di tabel ruangan, lalu klik **Beri kategori** untuk mengisinya sekaligus; pilihan itu menggantikan kategori barang yang dicentang.
3. Di **Gedung & Jaringan**, isi luas gedung bila diketahui, serta bandwidth beserta bukti pengukurannya. Bila ada beberapa lokasi, pilih lokasinya dulu. Di samping formulir tampil hasilnya di Rekap Sarpras menurut data yang tersimpan. Di bagian **Dokumen jaringan** (setelah migrasi 15), unggah hasil uji kecepatan tiap ruang, bukti layanan ISP, dan peta jangkauan Wi-Fi.
4. Di **Perangkat Lunak**, catat aplikasi yang dipakai beserta jenis lisensinya.
5. Periksa **Sivitas per Lokasi** (lihat di bawah).

#### Sivitas per Lokasi

Jumlah sivitas tidak diketik, tetapi dihitung dari anggota SLiMS yang **aktif**: tidak tertunda dan belum kedaluwarsa. Hilangkan centang tipe anggota yang bukan sivitas, misalnya anggota luar. Perpustakaan yang ruangannya berada di satu lokasi menghitung semua anggota aktif untuk lokasi itu tanpa pengaturan lain.

SLiMS tidak mencatat lokasi anggota. Bila ruangan tersebar di beberapa lokasi, setiap anggota ditempatkan menurut urutan berikut:

1. **Institusi** anggota yang dipetakan ke lokasi, misalnya program studi atau kelas. Huruf besar-kecil dan spasi tidak dibedakan. Bila nama institusi menyebut tempat yang hanya ada di satu nama lokasi, misalnya "Keperawatan Blora", plugin menyarankan lokasinya; klik **Terapkan saran** untuk memakai semua saran sekaligus.
2. **Tipe anggota** yang dipetakan ke lokasi, untuk perpustakaan yang membuat tipe anggota per kampus.
3. **Lokasi bawaan**, bila diisi.

Anggota lainnya tampil sebagai **belum dipetakan** dan tidak dihitung untuk lokasi mana pun.

Isian Institusi di SLiMS berupa teks bebas, sehingga satu program studi bisa tertulis dengan beberapa ejaan. Tab **Perbaiki institusi** mengelompokkan ejaan yang mirip (berbeda huruf besar-kecil, spasi, tanda baca, atau salah ketik satu-dua huruf). Ejaan dengan jenjang atau nomor berbeda, misalnya D-III dan D-IV atau Kelas 7A dan 7B, tidak dikelompokkan. Pilih ejaan yang benar, lalu klik **Gabungkan**; Institusi anggota di SLiMS ikut diperbaiki. Anda juga dapat memilih beberapa baris di tab Institusi lalu klik **Gabungkan ejaan**. Setiap perbaikan tercatat di **Riwayat perbaikan** dan dapat diurungkan; anggota yang institusinya diubah lagi sejak itu tidak ikut dikembalikan. Memperbaiki institusi memerlukan hak tulis **Keanggotaan** selain hak tulis Stock Take.

Rekap dihitung per **lokasi perpustakaan** (lokasi SLiMS yang dipilih pada tiap ruangan). Perpustakaan dengan satu lokasi langsung melihat rekapnya. Bila ruangan tersebar di beberapa lokasi:

- Halaman dibuka pada **Semua lokasi**: perbandingan jumlah aspek yang sudah baik, perlu perhatian, dan belum ada data di tiap lokasi, lalu kondisi gabungan institusi.
- Kondisi gabungan tiap aspek adalah rata-rata lokasi yang memiliki data (Sangat baik 4, Baik 3, Cukup 2, Kurang 1; dibulatkan ke kondisi terdekat). Lokasi yang belum memiliki data tidak ikut dirata-rata, tetapi jumlahnya ditampilkan.
- Pilih sebuah lokasi untuk melihat rekapnya sendiri. **Cetak rekap** mencetak yang sedang dibuka: satu lokasi, atau gabungan beserta tabel perbandingannya.
- **Gedung & Jaringan** diisi untuk tiap lokasi, dan sivitas tiap lokasi berasal dari **Sivitas per Lokasi**. **Perangkat Lunak** tetap satu daftar dan dihitung sama di semua lokasi.
- Ruangan yang belum diberi lokasi tidak masuk ke rekap lokasi mana pun; tetapkan lokasinya di **Ruangan & Barang**.

Bagian atas halaman merangkum berapa aspek yang sudah baik dan berapa yang perlu perhatian; saring daftarnya dengan tombol **Perlu perhatian**, **Belum ada data**, atau **Sudah baik**. Setiap aspek menampilkan kondisinya (Sangat baik, Baik, Cukup, Kurang). Klik sebuah aspek untuk melihat syarat yang sudah dan belum terpenuhi, rincian datanya, dan saran perbaikan dengan tombol menuju menu tempat datanya diisi. Klik **Cetak rekap** untuk PDF dalam gaya LaTeX, ISO, atau kop institusi.

### Label QR code

Pada halaman ruangan, klik **Cetak label**. Anda dapat memilih ukuran lembar (A4 3×8, A4 2×7, atau stiker 50×30 mm) dan label pertama yang masih kosong. QR code dapat mengarah ke:

- **Halaman publik**: informasi barang yang dapat dibuka tanpa login, tanpa harga dan nama petugas. Tautan ditandatangani sehingga ID barang tidak dapat ditebak.
- **Khusus petugas**: detail barang di panel admin, setelah login.

Cetak dengan skala **100%** agar label tepat pada lembar.

### Kop institusi

Buka **Pengaturan Cetak → Template kop**, lalu unggah PDF kop surat Anda. Halaman 1 menjadi latar halaman pertama laporan; halaman 2 (opsional) menjadi latar halaman berikutnya. Atur area konten dan font isi, lalu klik **Coba cetak** untuk melihat hasilnya.

Jika unggahan ditolak, simpan ulang PDF sebagai **PDF/A** lalu unggah kembali.

Nomor dokumen, revisi, dan tanggal terbit tiap jenis PDF diatur di **Pengaturan Cetak → Nomor dokumen**.

## Aplikasi Klaras InvenSync

Klaras InvenSync adalah aplikasi HP untuk petugas. Dengan aplikasi ini, petugas dapat memotret barang, memindai label QR, mengisi pemeriksaan, melapor kerusakan, dan memindai eksemplar saat stock opname, termasuk saat offline. Semua fitur plugin tetap dapat dipakai tanpa aplikasi.

Untuk mengaktifkan aplikasi:

1. Daftarkan perpustakaan Anda di Klaras Panel dan buat API key.
2. Pasang plugin **SLiMS Connect** (memerlukan PHP 8.1 atau lebih baru), lalu isi API key di **System → SLiMS Connect**. SLiMS harus dapat dibuka melalui HTTPS.
3. Buka **Stock Take → Aplikasi InvenSync**, lalu klik **Izinkan aplikasi**. Langkah ini memerlukan hak tulis System.

Petugas masuk dengan akun SLiMS yang memiliki hak **Stock Take**. Anda dapat mencabut sesi satu perangkat atau mematikan aplikasi sepenuhnya dari halaman yang sama.

## Agent AI

Aplikasi AI seperti Claude dapat bekerja di Klaras Inven atas nama petugas: membuat jadwal pemeriksaan dan checklist, menulis laporan kerusakan, merangkum laporan pengawasan dan Rekap Sarpras, serta mencari ruangan dan barang. Aplikasi AI tersambung lewat Klaras Panel; kata sandi SLiMS tidak pernah dimasukkan di aplikasi AI atau di Klaras.

Untuk menyalakannya:

1. Pastikan aplikasi InvenSync sudah diizinkan (bagian sebelumnya).
2. Di **Stock Take → Aplikasi InvenSync**, klik **Izinkan agent AI**. Langkah ini memerlukan hak tulis System.

Untuk menyambungkan, petugas menambahkan konektor `https://panel.klaras.id/mcp/inven` di aplikasi AI-nya, memilih perpustakaannya, lalu menyetujui permintaan di SLiMS (masuk ke SLiMS bila belum). Aplikasi AI hanya bisa melakukan yang diizinkan hak Stock Take petugas itu, dan semua perubahannya tercatat di log SLiMS atas namanya.

Sesi agent tampil dengan tanda **Agent AI** di daftar perangkat dan dapat dicabut satu per satu. **Matikan agent AI** memutus semua aplikasi AI sekaligus tanpa memengaruhi aplikasi HP.

## Data pemakaian

Sekali sehari, plugin mengirim ringkasan pemakaian ke Klaras agar plugin gratis ini dapat terus dirawat. Laporan berisi:

- Nama perpustakaan dan alamat SLiMS.
- Versi plugin, SLiMS Connect, SLiMS, PHP, dan database.
- Jumlah ruangan, barang, pemeriksaan, temuan, dan sesi stock opname.
- Jumlah data Rekap Sarpras yang sudah diisi: ruangan yang memiliki luas dan area, barang yang berkategori, aplikasi di Perangkat Lunak, dan lokasi yang mengisi Gedung & Jaringan.
- Kemajuan Sivitas per Lokasi: jumlah institusi dan tipe anggota yang sudah dipetakan, jumlah perbaikan institusi, dan apakah lokasi bawaan diisi. Jumlah anggota, nama institusi, dan lokasinya tidak dikirim.
- Apakah agent AI diizinkan, berapa petugas yang memakai aplikasi HP dan agent AI, serta jumlah perubahan yang dibuat agent AI (bukan isinya).
- Frekuensi pemakaian fitur, serta galat teknis yang telah dibersihkan dari isinya.

Plugin **tidak pernah** mengirim isi inventaris, nama atau kode barang, nama ruangan, nama aplikasi, angka gedung dan jaringan, data anggota, maupun data petugas.

Bila Anda ingin data perpustakaan Anda dihapus, tulis ke [privasi@klaras.id](mailto:privasi@klaras.id) dengan menyebut nama perpustakaan dan alamat SLiMS Anda.

## Pemecahan masalah

**PDF gagal dibuat.** Periksa log PHP, pastikan folder `vendor` ada, dan pastikan folder cache SLiMS dapat ditulis.

**Kode PHP tampil saat mencetak.** Buka PDF melalui tombol **Cetak** di plugin. Jangan membuka `plugins/inventaris-barang/print.php` secara langsung.

**Galat `setLogger(...): void`.** Trait PSR Log bawaan SLiMS di `lib/psr-log-aware-trait` belum kompatibel. Tambahkan tipe kembalian `: void` pada `setLogger` di `MpdfPsrLogAwareTrait.php` dan `PsrLogAwareTrait.php`.

**Kolom `filename` tidak ditemukan saat menyimpan barang.** Jalankan semua migrasi di **System → Plugins**.

## Pengembangan

### Menjalankan tes

Tes tanpa database dapat langsung dijalankan dari folder plugin:

```sh
php tests/pdf_template_test.php
php tests/security_controls_test.php
php tests/hardening_test.php
php tests/history_workbook_test.php
php tests/menu_structure_test.php
php tests/sarpras_combine_test.php
php tests/sarpras_categories_test.php
php tests/inventory_catalog_test.php
php tests/network_documents_test.php
php tests/software_list_test.php
php tests/room_areas_test.php
php tests/agent_codes_test.php
php tests/sivitas_test.php
node tests/watch_forms_test.cjs
```

Tes integrasi memerlukan database MySQL khusus pengujian. Tes membuat tabel berawalan acak dan menghapusnya setelah selesai.

```sh
export INVENTORY_TEST_DSN='mysql:host=127.0.0.1;dbname=uji'
export INVENTORY_TEST_USER=… INVENTORY_TEST_PASSWORD=…
php tests/watch_integration_test.php
php tests/telemetry_test.php
SLIMS_CONNECT_DIR=/path/ke/slims-connect php tests/invensync_api_test.php
```

### Membuat rilis

Versi plugin tercatat di baris `Version:` pada `inventory.plugin.php`.

```sh
tools/release.sh 2.4.0                # set versi, build frontend, commit, dan tag v2.4.0
git push origin main --follow-tags    # GitHub Actions membangun zip dan menerbitkan rilis
```

Tag berakhiran `-rc.1` atau `-beta.1` diterbitkan sebagai *pre-release* dan tidak ditawarkan sebagai pembaruan.

Untuk mengarahkan laporan data pemakaian ke panel lain saat pengembangan, atur `KLARAS_PANEL_URL`.

## Lisensi pihak ketiga

Plugin ini menyertakan [Alpine.js](https://alpinejs.dev) (MIT), [PDF.js](https://mozilla.github.io/pdf.js/) (Apache-2.0), dan font CMU Serif (SIL Open Font License).
