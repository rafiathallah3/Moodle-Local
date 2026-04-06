<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for component 'assignsubmission', language 'id'.
 *
 * @package   mod_assignsubmission
 * @copyright 2026 Custom
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['modulename'] = 'Pengumpulan Tugas';
$string['modulename_help'] = 'Aktivitas Pengumpulan Tugas memungkinkan dosen dan asisten dosen untuk mengunggah gambar tugas mahasiswa secara massal, mengekstrak nama mahasiswa secara otomatis melalui OCR, dan menilai pengumpulan secara otomatis menggunakan AI.

Gunakan aktivitas ini untuk:
* Mengunggah 30-40 gambar tugas mahasiswa sekaligus
* Mengidentifikasi nama mahasiswa secara otomatis dari gambar
* Menilai pengumpulan tugas secara otomatis dengan umpan balik yang didukung oleh AI';
$string['modulenameplural'] = 'Pengumpulan Tugas';
$string['pluginname'] = 'Pengumpulan Tugas';
$string['pluginadministration'] = 'Administrasi Pengumpulan Tugas';

// Form fields.
$string['assignmentname'] = 'Nama tugas';
$string['questiontext'] = 'Deskripsi Tugas/Pertanyaan';
$string['questiontext_help'] = 'Jelaskan tugas atau pertanyaan. Teks ini digunakan sebagai patokan oleh penilai AI untuk mengevaluasi tugas mahasiswa.';
$string['maxmark'] = 'Nilai maksimal';
$string['maxmark_help'] = 'Nilai maksimal yang bisa didapatkan oleh mahasiswa untuk tugas ini.';

// View page.
$string['uploadsubmissions'] = 'Unggah Tugas';
$string['uploadzone_label'] = 'Seret & lepas gambar tugas mahasiswa di sini, atau klik untuk mencari file';
$string['uploadzone_hint'] = 'Format yang didukung: JPG, JPEG, PNG, GIF, WEBP';
$string['submissionstable'] = 'Tugas Mahasiswa';
$string['studentname'] = 'Nama Mahasiswa';
$string['mark'] = 'Nilai';
$string['feedback'] = 'Umpan Balik';
$string['status'] = 'Status';
$string['actions'] = 'Aksi';
$string['imagepreview'] = 'Gambar';
$string['ocrtext'] = 'Teks Hasil Ekstraksi';
$string['diagnose'] = 'Diagnosis';
$string['diagnosing'] = 'Mendiagnosis...';
$string['autograde_all'] = 'Nilai Otomatis Semua';
$string['autograde_warning'] = 'Peringatan: Menilai otomatis semua pengumpulan tugas akan membuat beberapa pemanggilan API AI. Hal ini dapat menghabiskan kuota API Gemini gratis Anda. Apakah Anda yakin ingin melanjutkan?';
$string['autograde_confirm'] = 'Ya, nilai otomatis semua';
$string['autograde_cancel'] = 'Batal';
$string['nosubmissions'] = 'Belum ada tugas yang diunggah.';
$string['uploadingfiles'] = 'Mengunggah dan memproses file...';
$string['deleteconfirm'] = 'Apakah Anda yakin ingin menghapus pengumpulan tugas ini?';
$string['editsubmission'] = 'Edit Tugas';
$string['edit_title'] = 'Edit Judul';

// Status labels.
$string['status_pending'] = 'Menunggu';
$string['status_processing'] = 'Memproses';
$string['status_graded'] = 'Dinilai';
$string['status_error'] = 'Kesalahan';

// Capabilities.
$string['assignsubmission:view'] = 'Lihat pengumpulan tugas';
$string['assignsubmission:addinstance'] = 'Tambahkan aktivitas Pengumpulan Tugas baru';
$string['assignsubmission:upload'] = 'Unggah tugas mahasiswa';
$string['assignsubmission:grade'] = 'Nilai tugas mahasiswa';

// Events.
$string['eventcoursemoduleviewed'] = 'Pengumpulan Tugas dilihat';

// Misc.
$string['privacy:metadata'] = 'Plugin Pengumpulan Tugas menyimpan gambar yang diunggah mahasiswa dan nilai serta umpan balik yang dihasilkan oleh AI.';
$string['search:activity'] = 'Pengumpulan Tugas';
$string['unnamed_student'] = 'Tanpa Nama';
$string['deletesubmission'] = 'Hapus';
$string['editsubmission'] = 'Edit';
$string['edit_title'] = 'Edit Pengumpulan';
$string['edit_save'] = 'Simpan Perubahan';
$string['of'] = 'dari';

// Edit description.
$string['editdescription'] = 'Edit Deskripsi';
$string['editdescription_title'] = 'Edit Deskripsi Tugas';
$string['editdescription_help'] = 'Deskripsi ini digunakan sebagai patokan oleh penilai AI ketika mengevaluasi tugas mahasiswa. Misalnya, tentukan format input/output yang diharapkan, kriteria rubrik, atau persyaratan tertentu.';
$string['descriptionsaved'] = 'Deskripsi berhasil disimpan.';
