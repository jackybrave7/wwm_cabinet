# WWM Cabinet — HTML Prototype

Static UI mockups for the personal cabinet (student + admin CMS).

Open **`index.html`** in a browser or serve locally.

### Windows — установка в `C:\projects\wwm-cabinet`

**Один раз** в PowerShell (от имени обычного пользователя):

```powershell
Set-ExecutionPolicy -Scope Process Bypass -Force
irm https://raw.githubusercontent.com/jackybrave7/wwm-cabinet/master/install-windows.ps1 | iex
```

Скрипт создаст `C:\projects\wwm-cabinet`, скопирует файлы и предложит открыть прототип.

**Вручную** (если Git уже установлен):

```powershell
New-Item -ItemType Directory -Force -Path C:\projects\wwm-cabinet
cd $env:TEMP
git clone -b master --depth 1 https://github.com/jackybrave7/wwm-cabinet.git wwm-tmp
Copy-Item -Recurse -Force wwm-tmp\* C:\projects\wwm-cabinet\
Remove-Item -Recurse -Force wwm-tmp
cd C:\projects\wwm-cabinet\prototype
.\start.bat
```

**Открыть прототип:**

```powershell
cd C:\projects\wwm-cabinet\prototype
.\start.bat
```

### macOS / Linux

```bash
cd wwm-cabinet/prototype
python3 -m http.server 8080
# http://localhost:8080/
```

## Screens

### Student (like AVO student cabinet)

| File | Purpose |
|------|---------|
| `login.html` | Sign in |
| `dashboard.html` | My courses (demo / paid badges) |
| `course.html` | Sections sidebar + lesson list, demo locks |
| `lesson.html` | Rich lesson: H2/H3, lists, **Kinescope/Vimeo** embed, materials |
| `lesson-demo-locked.html` | Upgrade prompt when demo lesson not included |

### Admin (like AVO course editor)

| File | Purpose |
|------|---------|
| `admin/courses.html` | Course list |
| `admin/students.html` | Students list — lessons opened / total |
| `admin/student-view.html` | Student detail — per-course lesson progress |
| `admin/course-edit.html` | General settings, **demo duration**, **demo lessons** toggles, sections |
| `admin/lesson-edit.html` | **Rich text toolbar**, video insert modal (Kinescope/Vimeo/YouTube) |

## Feature mapping → AVO

| AVO | Prototype |
|-----|-----------|
| Course sections | `course-edit.html` → Structure tab |
| Lessons in course | Lesson rows with drag handle |
| Lesson HTML body | `lesson-edit.html` contenteditable + toolbar |
| H1/H2/H3 | H2, H3 in toolbar (H1 = lesson title field) |
| Video from Kinescope/Vimeo | Video modal + sidebar + `lesson.html` embed |
| Demo access duration | Course → Demo tab (24/48/72h) |
| Which lessons in demo | Per-lesson **Demo** checkbox |
| Paid vs demo student view | `course.html` locked items, `lesson-demo-locked.html` |

## Next step (PHP app)

Use these HTML/CSS patterns in `wwm-cabinet/templates/` and add:

- Admin routes (`/admin/courses`, `/admin/courses/{slug}`, `/admin/lessons/{id}`)
- DB tables: `sections`, `lessons` (html_body, video_json, is_demo)
- Course settings: `demo_hours`, `demo_enabled`
