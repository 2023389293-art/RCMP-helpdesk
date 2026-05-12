<!-- dept_admin/afsmd/_foot_scripts.php --> 
<script>
// Active nav highlight
document.querySelectorAll('.nav-item').forEach(el => {
  if (el.getAttribute('href') === window.location.pathname.split('/').pop()) {
    el.classList.add('active');
  }
});

// Sidebar mobile toggle
const sidebar    = document.getElementById('sidebar');
const mobToggle  = document.getElementById('mobToggle');
const mobOverlay = document.getElementById('mobOverlay');

function openSidebar() {
  sidebar.classList.add('open');
  mobOverlay.classList.add('open');
}

function closeSidebar() {
  sidebar.classList.remove('open');
  mobOverlay.classList.remove('open');
}

mobToggle.addEventListener('click', openSidebar);
mobOverlay.addEventListener('click', closeSidebar);

// Close sidebar when a nav link is tapped on mobile
document.querySelectorAll('.nav-item').forEach(el => {
  el.addEventListener('click', () => {
    if (window.innerWidth <= 900) closeSidebar();
  });
});
</script>