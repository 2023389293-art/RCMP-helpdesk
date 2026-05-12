<?php
// complaint/layout_end.php
?>
  </div><!-- /.content -->
</div><!-- /.main -->

<!-- ── SHARED JS: User Chip Dropdown ── -->
<script>
  function toggleUserDropdown(e) {
    e.stopPropagation();
    const wrapper = document.getElementById('userChipWrapper');
    const caret   = document.getElementById('chipCaret');
    const isOpen  = wrapper.classList.toggle('open');
    caret.style.transform = isOpen ? 'rotate(180deg)' : 'rotate(0deg)';
  }
  document.addEventListener('click', function () {
    const wrapper = document.getElementById('userChipWrapper');
    const caret   = document.getElementById('chipCaret');
    if (wrapper && wrapper.classList.contains('open')) {
      wrapper.classList.remove('open');
      caret.style.transform = 'rotate(0deg)';
    }
  });
</script>

<?php if (!empty($extraFoot)) echo $extraFoot; ?>

</body>
</html>