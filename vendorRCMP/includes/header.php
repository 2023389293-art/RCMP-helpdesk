<?php
// vendorRCMP/includes/header.php
// Requires: session_start() and $vendor_name / $vendor_company already set by the caller
$vendor_name    = htmlspecialchars($_SESSION['vendor_name']    ?? 'Vendor');
$vendor_company = htmlspecialchars($_SESSION['vendor_company'] ?? '');
?>
<?php
// Count pending departments for this vendor
$pendingStmt = $conn->prepare(
    "SELECT COUNT(*) FROM vendor_departments WHERE vendor_id = ? AND status = 'pending'"
);
$pendingStmt->bind_param('i', $_SESSION['vendor_id']);
$pendingStmt->execute();
$pendingStmt->bind_result($pendingDeptCount);
$pendingStmt->fetch();
$pendingStmt->close();

// Fetch primary PIC (Person In Charge) for this vendor
$picStmt = $conn->prepare(
    "SELECT full_name, position, phone FROM vendor_staff WHERE vendor_id = ? AND is_primary = 1 LIMIT 1"
);
$picStmt->bind_param('i', $_SESSION['vendor_id']);
$picStmt->execute();
$picResult = $picStmt->get_result()->fetch_assoc();
$picStmt->close();
$vendor_pic_name     = htmlspecialchars($picResult['full_name'] ?? '');
$vendor_pic_position = htmlspecialchars($picResult['position']  ?? '');
$vendor_pic_phone    = htmlspecialchars($picResult['phone']     ?? '');
?>
<nav>
  <a class="nav-brand" href="<?php echo $navBrandHref ?? 'dashboard.php'; ?>">
    <img src="<?php echo $navImgPath ?? '../img/RCMP.png'; ?>" alt="UniKL RCMP"/>
    <div>
      <div class="nav-brand-sub">UniKL RCMP</div>
      <div class="nav-brand-text">Vendor Portal</div>
    </div>
  </a>
  <div class="nav-right">
    <div class="vendor-chip" onclick="openVendorModal()" style="cursor:pointer;user-select:none;" title="Click to edit profile">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
      <?php echo $vendor_company; ?>
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" style="opacity:0.55;margin-left:2px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    </div>
    <a href="vendor_staff.php" class="btn-logout" title="Staff & Departments" style="padding:8px 14px;position:relative;display:inline-flex;align-items:center;gap:7px;">
      <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <line x1="19" y1="8" x2="19" y2="14"/>
        <line x1="16" y1="11" x2="22" y2="11"/>
      </svg>
      <?php if (!empty($pendingDeptCount) && $pendingDeptCount > 0): ?>
      <span style="position:absolute;top:-7px;right:-7px;background:#D97706;color:#fff;font-size:9px;font-weight:800;min-width:16px;height:16px;border-radius:20px;display:flex;align-items:center;justify-content:center;line-height:1;padding:0 3px;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.15);">
        <?php echo $pendingDeptCount; ?>
      </span>
      <?php endif; ?>
    </a>
    <a href="../vendor_login.php?logout=1" class="btn-logout">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Logout
    </a>
  </div>
</nav>

<!-- ── VENDOR PROFILE MODAL ── -->
<div id="vendorModal" style="display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;">
  <div onclick="closeVendorModal()" style="position:absolute;inset:0;background:rgba(0,0,0,0.35);backdrop-filter:blur(2px);"></div>
  <div style="position:relative;background:#fff;border-radius:16px;padding:32px 36px;width:100%;max-width:760px;max-height:90vh;overflow-y:auto;margin:16px;box-shadow:0 20px 60px rgba(0,0,0,0.18);">
    <button onclick="closeVendorModal()" style="position:absolute;top:14px;right:14px;background:none;border:none;cursor:pointer;color:var(--g400);padding:4px;border-radius:6px;display:flex;align-items:center;justify-content:center;">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
    </button>
    <div style="margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--g200);">
      <h2 style="font-size:17px;font-weight:800;color:var(--g900);display:flex;align-items:center;gap:8px;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </h2>
      <p style="font-size:13px;color:var(--g400);margin-top:3px;"><?php echo $vendor_company; ?> — Vendor Account</p>
    </div>
    <!-- View mode -->
    <div id="profileView">
      <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
        <div style="background:var(--g100);border-radius:10px;padding:12px 14px;">
  <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--g400);margin-bottom:3px;">Address</div>
  <div style="font-size:14px;font-weight:600;color:var(--g700);">
    <?php
      $parts = array_filter([
        $_SESSION['vendor_address'] ?? '',
        $_SESSION['vendor_city']    ?? '',
        $_SESSION['vendor_postcode']?? '',
        $_SESSION['vendor_state']   ?? '',
      ]);
      echo htmlspecialchars(implode(', ', $parts) ?: '—');
    ?>
  </div>
</div>
        <div style="background:var(--g100);border-radius:10px;padding:12px 14px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--g400);margin-bottom:3px;">Email</div>
          <div style="font-size:15px;font-weight:600;color:var(--g700);"><?php echo htmlspecialchars($_SESSION['vendor_email'] ?? '—'); ?></div>
        </div>
        <div style="background:var(--g100);border-radius:10px;padding:12px 14px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--g400);margin-bottom:3px;">Phone</div>
          <div style="font-size:15px;font-weight:600;color:var(--g700);"><?php echo htmlspecialchars($_SESSION['vendor_phone'] ?? '—'); ?></div>
        </div>
        <div style="background:var(--g100);border-radius:10px;padding:12px 14px;">
          <div style="font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--g400);margin-bottom:3px;">Person In Charge</div>
          <div style="font-size:15px;font-weight:600;color:var(--g700);"><?php echo $vendor_pic_name ?: '—'; ?></div>
          <?php if ($vendor_pic_position || $vendor_pic_phone): ?>
          <div style="font-size:13px;color:var(--g500);margin-top:2px;">
            <?php echo $vendor_pic_position; ?><?php echo ($vendor_pic_position && $vendor_pic_phone) ? ' · ' : ''; ?><?php echo $vendor_pic_phone; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <button onclick="switchToEdit()" style="width:100%;padding:11px;border-radius:10px;background:var(--navy);border:none;color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .15s;">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        Edit Profile
      </button>
    </div>
    <!-- Edit mode -->
    <div id="profileEdit" style="display:none;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 20px;margin-bottom:20px;">

        <!-- LEFT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Company Name</label>
            <input id="editCompany" type="text" value="<?php echo htmlspecialchars($_SESSION['vendor_company'] ?? ''); ?>"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
              onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Address</label>
            <input id="editAddress" type="text" value="<?php echo htmlspecialchars($_SESSION['vendor_address'] ?? ''); ?>"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
              onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">City</label>
              <input id="editCity" type="text" value="<?php echo htmlspecialchars($_SESSION['vendor_city'] ?? ''); ?>"
                style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Postcode</label>
              <input id="editPostcode" type="text" value="<?php echo htmlspecialchars($_SESSION['vendor_postcode'] ?? ''); ?>"
                maxlength="5" inputmode="numeric"
                style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
            </div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">State</label>
            <select id="editState" style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;background:#fff;">
              <?php
              $states = ['Johor','Kedah','Kelantan','Melaka','Negeri Sembilan','Pahang','Perak','Perlis','Pulau Pinang','Sabah','Sarawak','Selangor','Terengganu','W.P. Kuala Lumpur','W.P. Labuan','W.P. Putrajaya'];
              $cur = $_SESSION['vendor_state'] ?? '';
              foreach($states as $st) echo '<option value="'.htmlspecialchars($st).'"'.($cur===$st?' selected':'').'>'.htmlspecialchars($st).'</option>';
              ?>
            </select>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Email</label>
            <input id="editEmail" type="email" value="<?php echo htmlspecialchars($_SESSION['vendor_email'] ?? ''); ?>"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
              onfocus="this.style.borderColor='var(--blue)'" onblur="validateEmail(this)"/>
            <div id="emailError" style="display:none;font-size:11px;color:#DC2626;margin-top:4px;">Please enter a valid email address.</div>
          </div>
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Phone</label>
            <input id="editPhone" type="tel" value="<?php echo htmlspecialchars($_SESSION['vendor_phone'] ?? ''); ?>"
              maxlength="11"
              oninput="this.value=this.value.replace(/[^0-9]/g,'');validatePhone(this);"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
              onfocus="this.style.borderColor='var(--blue)'" onblur="validatePhone(this)"/>
            <div id="phoneError" style="display:none;font-size:11px;color:#DC2626;margin-top:4px;">Phone number must be 9–11 digits (numbers only).</div>
          </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div style="display:flex;flex-direction:column;gap:12px;">
          <div>
            <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">Person In Charge (Name)</label>
            <input id="editPicName" type="text" value="<?php echo $vendor_pic_name; ?>"
              style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
              onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">PIC Position</label>
              <input id="editPicPosition" type="text" value="<?php echo $vendor_pic_position; ?>"
                style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
            </div>
            <div>
              <label style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;display:block;margin-bottom:5px;">PIC Phone</label>
              <input id="editPicPhone" type="tel" value="<?php echo $vendor_pic_phone; ?>"
                maxlength="11"
                oninput="this.value=this.value.replace(/[^0-9]/g,'');"
                style="width:100%;padding:9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;"
                onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
            </div>
          </div>
          <div style="background:var(--g100);border-radius:12px;padding:20px;height:100%;display:flex;flex-direction:column;gap:12px;">
            <div style="font-size:12px;font-weight:700;color:var(--g500);letter-spacing:.05em;text-transform:uppercase;padding-bottom:10px;border-bottom:1px solid var(--g200);">
              Change Password <span style="font-weight:400;color:var(--g400);text-transform:none;letter-spacing:0;">(optional)</span>
            </div>
            <div>
              <label style="font-size:12px;font-weight:600;color:var(--g500);display:block;margin-bottom:5px;">Current Password</label>
              <div style="position:relative;">
                <input id="editOldPassword" type="password" placeholder="Enter current password" autocomplete="new-password"
  style="width:100%;padding:9px 38px 9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;background:#fff;"
  onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
                <button type="button" onclick="togglePwd('editOldPassword','eyeOld')" tabindex="-1"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:2px;color:var(--g400);display:flex;align-items:center;">
                  <svg id="eyeOld" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
            </div>
            <div>
              <label style="font-size:12px;font-weight:600;color:var(--g500);display:block;margin-bottom:5px;">New Password</label>
              <div style="position:relative;">
                <input id="editPassword" type="password" placeholder="Enter new password"
                  style="width:100%;padding:9px 38px 9px 12px;border:1.5px solid var(--g300);border-radius:8px;font-size:14px;font-family:inherit;color:var(--g700);outline:none;transition:border-color .15s;background:#fff;"
                  onfocus="this.style.borderColor='var(--blue)'" onblur="this.style.borderColor='var(--g300)'"/>
                <button type="button" onclick="togglePwd('editPassword','eyeNew')" tabindex="-1"
                  style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;padding:2px;color:var(--g400);display:flex;align-items:center;">
                  <svg id="eyeNew" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
            </div>
            <div style="margin-top:auto;padding-top:10px;border-top:1px solid var(--g200);">
              <p style="font-size:12px;color:var(--g400);line-height:1.6;">Leave both fields blank if you don't want to change your password.</p>
            </div>
          </div>
        </div>

      </div>
      <div id="editMsg" style="display:none;font-size:13px;font-weight:600;padding:8px 12px;border-radius:8px;margin-bottom:12px;"></div>
      <div style="display:flex;gap:10px;">
        <button onclick="switchToView()" style="flex:1;padding:11px;border-radius:10px;background:transparent;border:1.5px solid var(--g300);color:var(--g700);font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:all .15s;"
          onmouseover="this.style.borderColor='var(--navy)';this.style.color='var(--navy)'" onmouseout="this.style.borderColor='var(--g300)';this.style.color='var(--g700)'">
          Cancel
        </button>
        <button onclick="saveProfile()" id="saveBtn" style="flex:2;padding:11px;border-radius:10px;background:var(--navy);border:none;color:#fff;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:background .15s;">
          Save Changes
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── Modal + Profile JS (shared) ── -->
<script>
function openVendorModal(){switchToView();document.getElementById('vendorModal').style.display='flex';document.body.style.overflow='hidden';}
function closeVendorModal(){document.getElementById('vendorModal').style.display='none';document.body.style.overflow='';}
function switchToEdit(){document.getElementById('profileView').style.display='none';document.getElementById('profileEdit').style.display='block';hideEditMsg();}
function switchToView(){document.getElementById('profileView').style.display='block';document.getElementById('profileEdit').style.display='none';hideEditMsg();}
function showEditMsg(msg,ok){var el=document.getElementById('editMsg');el.textContent=msg;el.style.display='block';el.style.background=ok?'#D1FAE5':'#FEE2E2';el.style.color=ok?'#065F46':'#991B1B';}
function hideEditMsg(){document.getElementById('editMsg').style.display='none';}
function validatePhone(input){var val=input.value;var err=document.getElementById('phoneError');if(val.length>0&&(val.length<9||val.length>11)){input.style.borderColor='#DC2626';if(err)err.style.display='block';return false;}else{input.style.borderColor='var(--g300)';if(err)err.style.display='none';return true;}}
function validateEmail(input){var val=input.value.trim();var err=document.getElementById('emailError');var ok=/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val);if(!ok){input.style.borderColor='#DC2626';if(err)err.style.display='block';return false;}else{input.style.borderColor='var(--g300)';if(err)err.style.display='none';return true;}}
function togglePwd(inputId,svgId){var input=document.getElementById(inputId);var svg=document.getElementById(svgId);if(input.type==='password'){input.type='text';svg.innerHTML='<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';}else{input.type='password';svg.innerHTML='<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';}}
async function saveProfile(){
  var btn=document.getElementById('saveBtn');
  var phoneInput=document.getElementById('editPhone');
  var emailInput=document.getElementById('editEmail');
  if(!validateEmail(emailInput)){showEditMsg('Please enter a valid email address.',false);return;}
  if(!validatePhone(phoneInput)){showEditMsg('Phone number must be 9–11 digits (numbers only).',false);return;}
  btn.textContent='Saving…';btn.disabled=true;
  var body=new FormData();
  body.append('company',  document.getElementById('editCompany').value.trim());
  body.append('address',  document.getElementById('editAddress').value.trim());
  body.append('city',     document.getElementById('editCity').value.trim());
  body.append('state',    document.getElementById('editState').value);
  body.append('postcode', document.getElementById('editPostcode').value.trim());
  body.append('phone',    phoneInput.value.trim());
  body.append('email',    emailInput.value.trim());
  body.append('pic_name',     document.getElementById('editPicName').value.trim());
  body.append('pic_position', document.getElementById('editPicPosition').value.trim());
  body.append('pic_phone',    document.getElementById('editPicPhone').value.trim());
  body.append('old_password',document.getElementById('editOldPassword').value);
  body.append('password',    document.getElementById('editPassword').value);
  try{
    var res=await fetch('update_vendor_profile.php',{method:'POST',body});
    var data=await res.json();
    if(data.success){
      showEditMsg('Profile updated successfully!',true);
      // Update nav chip label without page reload
      var chips=document.querySelectorAll('.vendor-chip');
      chips.forEach(function(chip){
        // Find the text node and update it
        chip.childNodes.forEach(function(node){
          if(node.nodeType===3&&node.textContent.trim()){node.textContent=' '+data.company+' ';}
        });
      });
      setTimeout(function(){switchToView();closeVendorModal();},1200);
    }else{showEditMsg(data.error||'Update failed.',false);}
  }catch(e){showEditMsg('Network error. Please try again.',false);}
  btn.textContent='Save Changes';btn.disabled=false;
}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeVendorModal();});

<?php if (!empty($_SESSION['vendor_first_login'])): ?>
// First-time login — auto-open profile modal in edit mode
window.addEventListener('DOMContentLoaded', function () {
  openVendorModal();
  switchToEdit();
  showEditMsg('Welcome! Please review and confirm your company details before continuing.', true);
});
<?php endif; ?>
</script>