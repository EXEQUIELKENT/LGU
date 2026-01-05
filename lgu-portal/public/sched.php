<?php
session_start();
require __DIR__ . '/db.php';

$firstName = $_SESSION['employee_first_name'] ?? 'User';

if (!isset($_SESSION['employee_logged_in']) || $_SESSION['employee_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch schedules
$schedules = [];
$sql = "SELECT * FROM maintenance_schedule ORDER BY schedule_date ASC";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $schedules = $result->fetch_all(MYSQLI_ASSOC);
}

// Logout
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit;
}
?>

<script>
const scheduleData = <?= json_encode($schedules, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Maintenance Schedule</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap');
*{margin:0;padding:0;box-sizing:border-box;font-family:'Poppins',sans-serif;}
body {
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
    min-height: 100vh;           /* let body grow if content is taller */
    background: url("cityhall.jpeg") center/cover no-repeat fixed;
    position: relative;
}
/* Full-page overlay for blur and dark tint */
body::before {
    content: "";
    position: fixed;              /* fixed ensures full viewport coverage */
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.35); /* semi-transparent dark layer */
    backdrop-filter: blur(6px);   /* blur effect */
    z-index: 0;
}
/* Sidebar Navigation */
.sidebar-nav {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: rgba(255, 255, 255, 0.795);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid rgba(255, 255, 255, 0.25);  /* glowing border */
    box-shadow: 0 4px 25px rgba(0,0,0,0.25);
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 0;
    z-index: 1000;
}
/* Top area: logo + nav links */
.sidebar-top {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px 0;
    overflow-y: auto;
}
/* LGU Logo */
.sidebar-nav .site-logo {
    margin-top: 5px;
    flex-direction: column;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding-bottom: 5px;
    width: calc(100% - 50px);
    margin-left: 25px;
    margin-right: 25px;
    box-sizing: border-box;
    margin-bottom: 20px;
    color: #fff;
}
.sidebar-nav .site-logo img {
    width: 120px;
    height: auto;
    object-fit: contain;
    border-radius: 10px;
}
/* Navigation Links */
.sidebar-nav .nav-list {
    list-style: none;
    font-size: 14px;
    padding: 0 20px;
    margin: 0;
    display: flex;
    flex-direction: column;
    flex-grow: 1;
}
.sidebar-nav .nav-list li {
    width: 100%;
    margin: 3px 0;
}
.sidebar-nav .nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    color: #000000;
    text-decoration: none;
    padding: 12px 20px;
    transition: all 0.3s ease;
    border-radius: 8px;
}
.sidebar-nav .nav-link.active,
.sidebar-nav .nav-link.active:hover {
    background: #3762c8; /* slightly lighter */
    color: #fff;
    transform: translateX(2px);
}
.sidebar-nav .nav-link:hover {
    background: #97a4c2; /* slightly lighter */
    transform: translateX(8px) scale(1.02);
}
/* Divider */
.sidebar-divider {
    border-bottom: 2px solid rgba(0, 0, 0, 0.551);
    width: calc(100% - 50px);
    margin: 20px 25px 0 25px;
}
/* User info at bottom */
.sidebar-nav .user-info {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 0;
    border-top: 1px solid rgba(255,255,255,0.2);
}
.sidebar-nav .user-welcome,
.sidebar-nav .user-rights {
    text-align: center;
    color: #000000;
    font-weight: 600;
    font-size: 0.95rem;
    margin-bottom: 5px;
}
.sidebar-nav .logout-btn {
    background: #3762c8; /* slightly lighter */
    border: 1px solid rgba(255,255,255,0.3);
    color: #fff;
    padding: 8px 12px;
    border-radius: 6px;
    cursor: pointer;
    transition: 0.3s ease;
}
.sidebar-nav .logout-btn:hover {
    background: #3762c8; /* slightly lighter */
    color: #fff;
    transform: translateY(-2px) scale(1.02);
}
.main-content {
    margin-left: 250px;
    padding: 20px 80px;
    position: relative;   /* stays above the overlay */
    z-index: 1;
    padding-bottom: 0px;          /* extra space at bottom */
}
.card{
    background:rgba(255,255,255,.92);
    border-radius:22px;
    padding:30px;
    box-shadow:0 10px 30px rgba(0,0,0,.25);
}
/* TOGGLE BUTTON */
.toggle-btn{
    margin-top:20px;
    background:#3762c8;
    color:#fff;
    border:none;
    padding:10px 18px;
    border-radius:10px;
    cursor:pointer;
    font-weight:600;
}
/* SCHEDULE LIST */
.schedule-item{
    display:flex;
    justify-content:space-between;
    padding:14px 0;
    border-bottom:1px solid rgba(0,0,0,.1);
}
.schedule-date{font-weight:600}
/* CALENDAR */
.calendar-header{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:15px;
    margin-top: -22px;
    font-weight:600;
}
.calendar-grid{
    display:grid;
    grid-template-columns:repeat(7,1fr);
    gap:8px;
}
.calendar-day {
    padding: 10px;
    text-align: center;
    border-radius: 8px;
    background: #f2f4f8;
    cursor: pointer;
    font-size: 13px;
    min-height: 80px; /* increased height to allow space for tasks */
    display: flex;
    flex-direction: column;
    justify-content: flex-start; /* tasks go under the date */
    gap: 5px; /* spacing between date and tasks */
}
.calendar-day .day-tasks {
    font-size: 11px;
    color: #333;
    margin-top: auto; /* pushes tasks to bottom */
    text-align: left;
}
.task-btn {
    background: #3762c8;
    color: #fff;
    border: none;
    border-radius: 4px;
    padding: 4px 8px;
    margin: 2px;
    cursor: pointer;
    font-size: 10px;
    font-weight: 600;
}
.task-btn:hover {
    background: #2a4fa3;
}
.calendar-day.has-event{
    background:#e0e7ff;
    font-weight:600;
}
.calendar-day:hover{background:#dbe3ff}
.calendar-details{
    margin-top:15px;
    font-size:13px;
}
.hidden{display:none}
.calendar-weekdays {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    text-align: center;
}
.calendar-weekdays div {
    padding: 6px 0;
    font-size: 13px;
}
/* Modal */
.modal {position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); display:flex; justify-content:center; align-items:center; z-index:2000;}
.modal.hidden {display:none !important;}
.modal-content {background:#fff; padding:20px; border-radius:12px; width:90%; max-width:500px; max-height:80%; overflow-y:auto; position:relative;}
.modal-close {position:absolute; top:10px; right:15px; font-size:22px; cursor:pointer;}
.modal h3 {margin-bottom:15px;}
.modal-task-item {margin-bottom:10px; padding:8px; border-left:4px solid #3762c8; background:#f0f4ff; border-radius:4px;}
</style>
</head>

<body>

<div class="sidebar-nav">
    <div class="sidebar-top">
        <div class="site-logo">
            <img src="logocityhall.png" alt="LGU Logo">
    <div class="sidebar-divider"></div>
        </div>
        <ul class="nav-list">
            <li><a href="employee.php" class="nav-link">Dashboard</a></li>
            <li><a href="requests.php" class="nav-link">Requests</a></li>
            <li><a href="reports.php" class="nav-link">Reports</a></li>
            <li><a href="#" class="nav-link active">Maintenance Schedule</a></li>
        </ul>
    </div>

    <div class="sidebar-divider"></div>

    <div class="user-info">
        <div class="user-welcome">Welcome, <?= htmlspecialchars($firstName) ?></div>
        <button id="logoutBtn" class="logout-btn">Logout</button>
    </div>
</div>

<div class="main-content">

    <div class="card">

        <!-- CALENDAR VIEW -->
        <div id="calendarView">
                <div class="calendar-header">
                <button id="prevMonth" class="toggle-btn" style="padding:5px 10px;">&#8592;</button>
                <span id="monthLabel"></span>
                <button id="nextMonth" class="toggle-btn" style="padding:5px 10px;">&#8594;</button>
            </div>
            <div class="calendar-weekdays">
                <div>Sunday</div>
                <div>Monday</div>
                <div>Tuesday</div>
                <div>Wednesday</div>
                <div>Thursday</div>
                <div>Friday</div>
                <div>Saturday</div>
            </div>
            <div class="calendar-grid" id="calendarGrid"></div>
            <div class="calendar-details" id="calendarDetails">
                Select a date to view schedule.
            </div>
        </div>
        <!-- LIST VIEW -->
        <div id="scheduleView" class="hidden">
            <?php if (empty($schedules)): ?>
                <p>No scheduled maintenance.</p>
            <?php else: foreach ($schedules as $row): ?>
                <div class="schedule-item">
                    <div>
                        <strong><?= htmlspecialchars($row['task']) ?></strong><br>
                        <?= htmlspecialchars($row['location']) ?>
                    </div>
                    <div class="schedule-date">
                        <?= date("F d, Y", strtotime($row['schedule_date'])) ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <button id="toggleBtn" class="toggle-btn">View Schedule</button>
    </div>
</div>

<!-- Modal -->
<div id="taskModal" class="modal hidden">
    <div class="modal-content">
        <span id="modalClose" class="modal-close">&times;</span>
        <h3>Scheduled Tasks</h3>
        <div id="modalBody"></div>
    </div>
</div>

<script>
const calendarGrid = document.getElementById('calendarGrid');
const calendarDetails = document.getElementById('calendarDetails');
const monthLabel = document.getElementById('monthLabel');
const toggleBtn = document.getElementById('toggleBtn');
const calendarView = document.getElementById('calendarView');
const scheduleView = document.getElementById('scheduleView');

let currentDate = new Date();
let showingCalendar = true;

toggleBtn.onclick = () => {
    showingCalendar = !showingCalendar;
    calendarView.classList.toggle('hidden');
    scheduleView.classList.toggle('hidden');
    toggleBtn.textContent = showingCalendar ? 'View Schedule' : 'View Calendar';
};

// Modal
const taskModal = document.getElementById('taskModal');
const modalBody = document.getElementById('modalBody');
const modalClose = document.getElementById('modalClose');
modalClose.onclick = ()=>taskModal.classList.add('hidden');
window.onclick = (e)=>{if(e.target===taskModal) taskModal.classList.add('hidden');};

function openModal(tasks){
    modalBody.innerHTML='';
    tasks.forEach(t=>{
        const div=document.createElement('div');
        div.className='modal-task-item';
        div.innerHTML=`<strong>Task:</strong> ${t.task}<br>
                       <strong>Location:</strong> ${t.location}<br>
                       <strong>Date:</strong> ${t.schedule_date}`;
        modalBody.appendChild(div);
    });
    taskModal.classList.remove('hidden');
}

function renderCalendar(){
    calendarGrid.innerHTML='';
    calendarDetails.innerHTML='Select a date to view schedule.';
    const year=currentDate.getFullYear();
    const month=currentDate.getMonth();
    monthLabel.textContent=currentDate.toLocaleString('default',{month:'long', year:'numeric'});
    const firstDay=new Date(year, month,1).getDay();
    const daysInMonth=new Date(year,month+1,0).getDate();
    for(let i=0;i<firstDay;i++) calendarGrid.innerHTML+='<div></div>';

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const events = scheduleData.filter(e => e.schedule_date === dateStr);

        const div = document.createElement('div');
        div.className = 'calendar-day' + (events.length ? ' has-event' : '');

        const dayNum = document.createElement('div');
        dayNum.textContent = d;
        div.appendChild(dayNum);

        if (events.length) {
            const tasksDiv = document.createElement('div');
            tasksDiv.className = 'day-tasks';
            events.forEach((e, i) => {
                const btn = document.createElement('button');
                btn.textContent = i + 1;
                btn.className = 'task-btn';
                // Only open modal on button click
                btn.addEventListener('click', (ev) => {
                    ev.stopPropagation(); // prevent parent div click
                    openModal([e]);       // show this task
                });
                tasksDiv.appendChild(btn);
            });
            div.appendChild(tasksDiv);
        }

        // Only update the calendar details on day click, no modal here
        div.addEventListener('click', () => {
            if(events.length){
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>`;
                calendarDetails.innerHTML += events.map(e => `• ${e.task} – ${e.location}`).join('<br>');
            } else {
                calendarDetails.innerHTML = `<strong>${dateStr}</strong><br>No scheduled maintenance.`;
            }
        });

        calendarGrid.appendChild(div);
    }
}

document.getElementById('prevMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()-1); renderCalendar();}
document.getElementById('nextMonth').onclick=()=>{currentDate.setMonth(currentDate.getMonth()+1); renderCalendar();}
renderCalendar();

// Logout
document.getElementById('logoutBtn').addEventListener('click', ()=>{
    if(confirm('Are you sure you want to logout?')) window.location.href='sched.php?logout=1';
});
</script>

</body>
</html>
