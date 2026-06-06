# single-domain-check

ตรวจสุขภาพ **plugin + theme** ของ WordPress แบบ **เจาะจงโดเมน** ด้วย PHP CLI รันตรงจาก GitHub

ใช้เช็คซ้ำทีละเว็บ — เอาโดเมนที่มีปัญหาจากการสแกนทั้งเซิร์ฟเวอร์ มายืนยันก่อนลงมือแก้ และเช็คซ้ำหลังแก้เสร็จว่าหายดีแล้ว

> อ่านอย่างเดียว 100% ไม่ลบ ไม่แก้ ไม่ย้ายไฟล์ — ปลอดภัย รันกี่ครั้งก็ได้
> ชุดเครื่องมือเดียวกัน: สแกนทั้งเซิร์ฟเวอร์ใช้ `plugin-health` / `theme-health` (คนละ repo) — ตัวนี้เจาะตรวจทีละโดเมน

## คุณสมบัติ

- **เจาะตรวจโดเมนที่ระบุ** ไม่ต้องสแกนทั้ง 7,000+ เว็บ — เร็ว เหมาะกับเช็คซ้ำ
- รับชื่อโดเมนได้ **2 แบบ**:
  - พิมพ์ชื่อตรง ๆ ทีละตัวหรือหลายตัว
  - ดึงรายชื่อจากไฟล์ **CSV บน GitHub** (คอลัมน์ `domain`, `cpanel_user`)
- ใช้ `cpanel_user` จาก CSV ช่วยหา path ตรง ๆ (ไปที่ `/home/<user>/`) → เร็วกว่าค้นทั้งเครื่อง
- ตรวจทั้ง plugin + theme (ค่าเริ่มต้น) หรือเลือกเฉพาะอย่างด้วย `--plugins` / `--themes`
- ใช้เกณฑ์เดียวกับสคริปต์สแกนหลัก (theme แยก parent/child/ทั่วไป)

## เกณฑ์การตรวจ

**ปลั๊กอิน:** ว่าง! (0 ไฟล์) / สงสัย (≤3) / ไม่มีหลัก (ไม่มี .php ที่มี Plugin Name) / ปกติ

**ธีม (แยกตามชนิด):**
| ชนิดธีม | เกณฑ์ "สงสัย" |
|---|---|
| blocksy (parent) | ขาดไฟล์หลัก (style.css/index.php/functions.php) หรือไฟล์ ≤ 50 |
| blocksy-child | ไฟล์ ≤ 2 |
| ธีมทั่วไป | ไฟล์ ≤ 10 |

## วิธีใช้

```bash
URL='https://raw.githubusercontent.com/ufavisionseoteam19/single-domain-check/main/domain-check.php'

# เช็คโดเมนเดียว (plugin + theme)
curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com

# เช็คหลายโดเมนพร้อมกัน
curl -s "$URL?v=$(date +%s)" | php -- mcm5699.com naza99.biz fast3699.com

# เฉพาะ plugin / เฉพาะ theme
curl -s "$URL?v=$(date +%s)" | php -- --plugins mcm5699.com
curl -s "$URL?v=$(date +%s)" | php -- --themes mcm5699.com

# แสดงเฉพาะที่มีปัญหา
curl -s "$URL?v=$(date +%s)" | php -- --only-issues mcm5699.com
```

### ดึงรายชื่อจาก CSV บน GitHub

ไฟล์ `check-domains-list.csv` บน repo (รูปแบบ):
```
domain,cpanel_user
allure369.biz,gonext02
amb44king.pro,gonext02
betax888.net,gonext02
```

แล้วรัน:
```bash
URL='https://raw.githubusercontent.com/ufavisionseoteam19/single-domain-check/main/domain-check.php'
LIST='https://raw.githubusercontent.com/ufavisionseoteam19/single-domain-check/main/check-domains-list.csv'
curl -s "$URL?v=$(date +%s)" | php -- --list="$LIST?v=$(date +%s)" --only-issues
```

> คอลัมน์ `cpanel_user` ไม่บังคับ แต่ถ้าใส่จะหา path เร็วขึ้นมาก (ไม่ต้องค้นทั้งเครื่อง)
> `?v=$(date +%s)` ต่อท้าย URL เพื่อกัน cache GitHub ให้ได้ไฟล์ล่าสุดเสมอ

## ตัวเลือก (Flags)

| Flag | ความหมาย | ค่าเริ่มต้น |
|---|---|---|
| `--plugins` | ตรวจเฉพาะ plugins | ตรวจทั้งคู่ |
| `--themes` | ตรวจเฉพาะ themes | ตรวจทั้งคู่ |
| `--list=URL` | ดึงรายชื่อโดเมนจาก CSV | - |
| `--base=PATH` | เปลี่ยนโฟลเดอร์ฐาน | /home |
| `--only-issues` | แสดงเฉพาะตัวมีปัญหา | แสดงทั้งหมด |

> อาร์กิวเมนต์ที่ไม่ขึ้นต้นด้วย `--` ถือเป็นชื่อโดเมน (ใส่ได้หลายตัว)

## ตัวอย่างผลลัพธ์

```
#######################################################
# โดเมน: allure369.biz  (บัญชี: gonext02)
#######################################################

  ที่ตั้ง: /home/gonext02/allure369.biz
    [PLUGIN]
    สถานะ        ไฟล์    ขนาด      ชื่อ
    ว่าง!        0       0B        blocksy-companion-pro
    [THEME]
    สถานะ        ไฟล์    ขนาด      ชื่อ
    (ไม่พบปัญหา)
```

## Workflow แนะนำ

1. สแกนทั้งเซิร์ฟเวอร์ด้วย `plugin-health` / `theme-health` → ได้รายชื่อโดเมนมีปัญหา
2. ใส่รายชื่อ + บัญชี cPanel ลงไฟล์ `check-domains-list.csv` บน repo นี้
3. รัน `domain-check.php --list=...` เช็คซ้ำยืนยันปัญหา **ก่อน** ลงมือแก้
4. แก้ไขเว็บ
5. รัน `--list` ซ้ำอีกครั้ง ยืนยันว่าแก้แล้วขึ้น "ไม่พบปัญหา"

## หยุดสคริปต์ (กรณีฉุกเฉิน)

```bash
pkill -f domain-check
```

## ความปลอดภัย

- read-only ไม่มีคำสั่งเขียน/ลบ/แก้ไฟล์เว็บ
- `--list` ดึง CSV จาก URL ที่ระบุเท่านั้น (ควรเป็น repo ของคุณเอง)

## ข้อกำหนด

- PHP CLI (ทดสอบบน PHP 8.3 ใช้ได้ตั้งแต่ PHP 7.x)
- ต้องเปิด allow_url_fopen เพื่อใช้ `--list` ดึง CSV (ปกติเปิดอยู่แล้ว)
- สิทธิ์อ่านโฟลเดอร์เว็บ (ปกติใช้ root)
