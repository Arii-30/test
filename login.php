<?php
require_once 'connection.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if (empty($username) || empty($password)) {
        $error = "Please fill in all fields.";
    } else {

        // SHA256 hash
        $password_hash = hash("sha256", $password);

        $stmt = mysqli_prepare($conn,
            "SELECT id, username, email, role, is_active 
             FROM users 
             WHERE username=? AND password_hash=? 
             LIMIT 1"
        );

        mysqli_stmt_bind_param($stmt, "ss", $username, $password_hash);
        mysqli_stmt_execute($stmt);

        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) == 1) {

            $user = mysqli_fetch_assoc($result);

            if (!$user["is_active"]) {
                $error = "Account disabled.";
            } else {

                $_SESSION["user_id"]  = $user["id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["email"]    = $user["email"];
                $_SESSION["role"]     = $user["role"];

                // update last login
                $update = mysqli_prepare($conn,
                    "UPDATE users SET last_login = NOW() WHERE id=?"
                );
                mysqli_stmt_bind_param($update, "i", $user["id"]);
                mysqli_stmt_execute($update);

                header("Location: index.php");
                exit;
            }

        } else {
            $error = "Wrong username or password.";
        }

        mysqli_stmt_close($stmt);
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SalesOS — Sign In</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
[data-theme="dark"] {
  --bg:#111318; --card:#1C1F26; --left:#16191F;
  --br:rgba(255,255,255,.08); --br2:rgba(255,255,255,.15);
  --tx:#F1F0ED; --t2:#8A8FA8; --t3:#3E4255;
  --ac:#4F8EF7; --ac2:#6BA3FF; --ag:rgba(79,142,247,.18);
  --re:#F76B6B; --gr:#4FD1A5;
  --inp-bg:rgba(255,255,255,.05); --inp-br:rgba(255,255,255,.12);
  --sh:0 24px 64px rgba(0,0,0,.6);
}
[data-theme="light"] {
  --bg:#F0EEE9; --card:#FFFFFF; --left:#F7F5F1;
  --br:rgba(0,0,0,.08); --br2:rgba(0,0,0,.15);
  --tx:#1A1A2E; --t2:#6B7280; --t3:#C4C9D4;
  --ac:#3B7DD8; --ac2:#2563EB; --ag:rgba(59,125,216,.12);
  --re:#E53E3E; --gr:#38A169;
  --inp-bg:transparent; --inp-br:rgba(0,0,0,.18);
  --sh:0 20px 60px rgba(0,0,0,.12);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%;font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--tx);transition:background .4s,color .4s;overflow:hidden}

.thm{position:fixed;top:18px;right:18px;z-index:200;width:40px;height:40px;border-radius:10px;background:var(--card);border:1px solid var(--br2);cursor:pointer;font-size:1rem;display:flex;align-items:center;justify-content:center;transition:all .3s;box-shadow:0 2px 12px rgba(0,0,0,.15)}
.thm:hover{transform:rotate(18deg) scale(1.1);border-color:var(--ac)}

.wrap{height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}

.card{width:100%;max-width:860px;background:var(--card);border-radius:28px;box-shadow:var(--sh);display:flex;overflow:hidden;min-height:520px;border:1px solid var(--br);animation:fadeUp .6s cubic-bezier(.16,1,.3,1) both}
@keyframes fadeUp{from{opacity:0;transform:translateY(28px) scale(.97)}to{opacity:1;transform:none}}

/* LEFT panel */
.left{width:45%;background:var(--left);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:40px 30px;position:relative;border-right:1px solid var(--br)}
.left::before{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:radial-gradient(circle,var(--ag),transparent 70%);top:50%;left:50%;transform:translate(-50%,-50%);pointer-events:none}

.char-wrap{position:relative;z-index:1;display:flex;flex-direction:column;align-items:center;gap:16px}

/* ── BEAR SVG ── */
.bear-svg{width:200px;height:200px;filter:drop-shadow(0 16px 32px rgba(0,0,0,.22));cursor:default;transition:transform .3s cubic-bezier(.34,1.56,.64,1)}
.bear-svg:hover{transform:scale(1.05) rotate(-3deg)}

.b-ear    {fill:#F5D491}
.b-ear-i  {fill:#E07A5F;opacity:.38}
.b-face   {fill:#F5D491}
.b-face-sh{fill:#C49030;opacity:.1}
.b-ew     {fill:#fff;transition:all .3s cubic-bezier(.34,1.56,.64,1)}
.b-ep     {fill:#2D1B00;transition:all .32s cubic-bezier(.34,1.56,.64,1);transform-origin:center}
.b-es     {fill:#fff;opacity:.75}
.b-bl     {fill:rgba(224,122,95,.42);opacity:0;transition:opacity .35s}
.b-nos    {fill:#E07A5F}
.b-mou    {stroke:#C49030;stroke-width:1.8;fill:none;stroke-linecap:round}
.b-arm    {transition:transform .45s cubic-bezier(.34,1.56,.64,1)}
.b-arm-l  {transform-origin:42px 108px}
.b-arm-r  {transform-origin:118px 108px}

/* Bear states */
#bear.squinting .b-ew{transform:scaleY(.12)}
#bear.squinting .b-ep{transform:scaleY(.08)}

#bear.peeking .b-arm-l{transform:rotate(-52deg) translateY(-5px)}
#bear.peeking .b-arm-r{transform:rotate(52deg) translateY(-5px)}
#bear.peeking .b-ew{transform:scale(1.2)}
#bear.peeking .b-bl{opacity:1}

#bear.surprised .b-ew{transform:scale(1.25)}
#bear.surprised .b-ep{transform:scale(1.15)}

#bear.hb{animation:hb .4s cubic-bezier(.34,1.56,.64,1)}
@keyframes hb{0%{transform:none}25%{transform:rotate(-8deg)}75%{transform:rotate(6deg)}100%{transform:none}}

.char-tag{display:inline-flex;align-items:center;gap:6px;background:var(--ag);color:var(--ac);padding:4px 12px;border-radius:100px;font-size:.68rem;font-weight:600;border:1px solid rgba(79,142,247,.2)}
.tag-dot{width:5px;height:5px;border-radius:50%;background:var(--ac);animation:blink 2s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}

.left-foot{position:absolute;bottom:24px;font-size:.68rem;color:var(--t3);text-align:center}
.left-foot a{color:var(--ac);text-decoration:none}
.left-foot a:hover{opacity:.75}

/* RIGHT panel */
.right{flex:1;display:flex;flex-direction:column;justify-content:center;padding:50px 48px}
.form-title{font-size:2rem;font-weight:800;color:var(--tx);letter-spacing:-.04em;margin-bottom:6px}
.form-sub{font-size:.8rem;color:var(--t2);margin-bottom:32px}

.roles{display:flex;gap:4px;background:var(--inp-bg);border:1px solid var(--inp-br);border-radius:10px;padding:3px;margin-bottom:24px}
[data-theme="dark"] .roles{background:rgba(255,255,255,.04)}
.rb{flex:1;padding:7px 4px;background:none;border:none;border-radius:7px;cursor:pointer;font-family:'Plus Jakarta Sans',sans-serif;font-size:.72rem;font-weight:500;color:var(--t2);transition:all .15s}
.rb.on{background:var(--ac);color:#fff;box-shadow:0 2px 10px var(--ag)}
.rb:not(.on):hover{color:var(--tx)}

.merr{display:flex;align-items:center;gap:8px;background:rgba(247,107,107,.1);border:1px solid rgba(247,107,107,.25);border-radius:10px;padding:10px 14px;font-size:.78rem;color:var(--re);margin-bottom:18px;animation:pop .3s cubic-bezier(.34,1.56,.64,1)}
@keyframes pop{from{opacity:0;transform:scale(.94)}to{opacity:1;transform:none}}
.merr svg{width:14px;height:14px;flex-shrink:0}

.field{position:relative;margin-bottom:20px}
.fi{width:100%;background:none;border:none;border-bottom:1.5px solid var(--inp-br);padding:12px 40px 10px 28px;font-family:'Plus Jakarta Sans',sans-serif;font-size:.92rem;color:var(--tx);outline:none;transition:border-color .2s}
.fi::placeholder{color:var(--t3);font-size:.85rem}
.fi:focus{border-bottom-color:var(--ac)}
.field.err .fi{border-bottom-color:var(--re)}
.fi-icon{position:absolute;left:0;top:50%;transform:translateY(-50%);color:var(--t2);transition:color .2s}
.fi-icon svg{width:16px;height:16px;display:block}
.field:focus-within .fi-icon{color:var(--ac)}
.tpw{position:absolute;right:0;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--t2);padding:4px;transition:color .2s;display:flex}
.tpw:hover{color:var(--ac)}
.tpw svg{width:16px;height:16px;display:block}

.frow{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;margin-top:-4px}
.rem{display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.78rem;color:var(--t2)}
.rem input{display:none}
.cb{width:16px;height:16px;border-radius:4px;border:1.5px solid var(--inp-br);display:flex;align-items:center;justify-content:center;transition:all .15s;flex-shrink:0}
.rem input:checked~.cb{background:var(--ac);border-color:var(--ac)}
.rem input:checked~.cb::after{content:'';display:block;width:4px;height:7px;border:1.5px solid #fff;border-top:none;border-left:none;transform:rotate(45deg) translate(-1px,-1px)}
.fg{font-size:.78rem;color:var(--ac);text-decoration:none}
.fg:hover{opacity:.75}

.sb{width:100%;padding:14px;background:var(--ac);border:none;border-radius:12px;font-family:'Plus Jakarta Sans',sans-serif;font-size:.92rem;font-weight:700;color:#fff;cursor:pointer;position:relative;overflow:hidden;transition:all .2s cubic-bezier(.34,1.56,.64,1);box-shadow:0 6px 24px var(--ag)}
.sb:hover{transform:translateY(-2px);box-shadow:0 12px 32px var(--ag);filter:brightness(1.08)}
.sb:active{transform:translateY(0)}
.sb.ld{pointer-events:none;opacity:.7}
.sb.ld .btx{opacity:0}
.spi{display:none;position:absolute;inset:0;align-items:center;justify-content:center}
.sb.ld .spi{display:flex}
.ring{width:18px;height:18px;border-radius:50%;border:2.5px solid rgba(255,255,255,.25);border-top-color:#fff;animation:spin .6s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.rip{position:absolute;border-radius:50%;background:rgba(255,255,255,.25);transform:scale(0);animation:ro .5s linear;pointer-events:none}
@keyframes ro{to{transform:scale(4);opacity:0}}

.form-foot{text-align:center;margin-top:20px;font-size:.78rem;color:var(--t2)}
.form-foot a{color:var(--ac);text-decoration:none;font-weight:600}

@media(max-width:700px){.left{display:none}.right{padding:36px 28px}}
</style>
</head>
<body>
<button class="thm" id="thm"><span id="thi">☀️</span></button>

<div class="wrap"><div class="card">

  <!-- LEFT: Bear -->
  <div class="left">
    <div class="char-wrap">

      <svg class="bear-svg" id="bear" viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg">
        <!-- Ears -->
        <ellipse class="b-ear"   cx="42"  cy="52" rx="20" ry="20"/>
        <ellipse class="b-ear-i" cx="42"  cy="52" rx="12" ry="12"/>
        <ellipse class="b-ear"   cx="118" cy="52" rx="20" ry="20"/>
        <ellipse class="b-ear-i" cx="118" cy="52" rx="12" ry="12"/>
        <!-- Head -->
        <ellipse class="b-face-sh" cx="82" cy="89" rx="44" ry="42"/>
        <ellipse class="b-face"    cx="80" cy="83" rx="48" ry="46"/>
        <!-- Blush cheeks -->
        <ellipse class="b-bl" cx="52"  cy="92" rx="13" ry="8"/>
        <ellipse class="b-bl" cx="108" cy="92" rx="13" ry="8"/>
        <!-- Left eye -->
        <ellipse class="b-ew" cx="62" cy="76" rx="11" ry="12"/>
        <ellipse class="b-ep" cx="62" cy="77" rx="7"  ry="7.5"/>
        <ellipse class="b-es" cx="65" cy="73" rx="2.5" ry="2.5"/>
        <!-- Right eye -->
        <ellipse class="b-ew" cx="98" cy="76" rx="11" ry="12"/>
        <ellipse class="b-ep" cx="98" cy="77" rx="7"  ry="7.5"/>
        <ellipse class="b-es" cx="101" cy="73" rx="2.5" ry="2.5"/>
        <!-- Nose -->
        <ellipse class="b-nos" cx="80" cy="90" rx="7" ry="5.5"/>
        <!-- Mouth -->
        <path class="b-mou" d="M74 97 Q80 103 86 97"/>
        <!-- Whiskers -->
        <path stroke="#C49030" stroke-width="1" opacity=".22" fill="none" d="M55 91L37 88M55 94L37 94M55 97L38 100"/>
        <path stroke="#C49030" stroke-width="1" opacity=".22" fill="none" d="M105 91L123 88M105 94L123 94M105 97L122 100"/>
        <!-- Left arm -->
        <g class="b-arm b-arm-l">
          <ellipse fill="#F5D491" cx="40" cy="108" rx="17" ry="11" transform="rotate(-10,40,108)"/>
          <ellipse fill="#C49030" opacity=".28" cx="26" cy="105" rx="5" ry="4" transform="rotate(-20,26,105)"/>
          <ellipse fill="#C49030" opacity=".28" cx="33" cy="101" rx="5" ry="4" transform="rotate(-8,33,101)"/>
          <ellipse fill="#C49030" opacity=".28" cx="40" cy="100" rx="5" ry="4"/>
        </g>
        <!-- Right arm -->
        <g class="b-arm b-arm-r">
          <ellipse fill="#F5D491" cx="120" cy="108" rx="17" ry="11" transform="rotate(10,120,108)"/>
          <ellipse fill="#C49030" opacity=".28" cx="134" cy="105" rx="5" ry="4" transform="rotate(20,134,105)"/>
          <ellipse fill="#C49030" opacity=".28" cx="127" cy="101" rx="5" ry="4" transform="rotate(8,127,101)"/>
          <ellipse fill="#C49030" opacity=".28" cx="120" cy="100" rx="5" ry="4"/>
        </g>
      </svg>

      <div class="char-tag"><div class="tag-dot"></div>SalesOS</div>
    </div>

    <div class="left-foot">No account? <a href="setup.php">Create admin →</a></div>
  </div>

  <!-- RIGHT: Form -->
  <div class="right">
    <div class="form-title">Log In</div>
    <div class="form-sub">Welcome back — sign in to your workspace</div>

    <div class="roles">
      <button class="rb on" data-role="admin"          onclick="sR(this)">Admin</button>
      <button class="rb"    data-role="accountant"     onclick="sR(this)">Accountant</button>
      <button class="rb"    data-role="representative" onclick="sR(this)">Rep</button>
    </div>

    <?php if ($error): ?>
    <div class="merr">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M8 1L1 13h14z"/><path d="M8 6v3M8 11v1"/></svg>
      <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="login.php" id="lf" novalidate>
      <input type="hidden" name="role" id="ri" value="admin">

      <div class="field" id="fu">
        <span class="fi-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="5.5" r="3"/><path d="M1.5 14c0-3 2.24-5.5 6.5-5.5s6.5 2.5 6.5 5.5"/></svg></span>
        <input type="text" class="fi" id="un" name="username" placeholder="Your Name" autocomplete="username">
      </div>

      <div class="field" id="fp">
        <span class="fi-icon"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="7" width="10" height="7" rx="1.5"/><path d="M5 7V5a3 3 0 016 0v2"/></svg></span>
        <input type="password" class="fi" id="pw" name="password" placeholder="Password" autocomplete="current-password">
        <button type="button" class="tpw" id="tpb">
          <svg id="iO" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.2"/></svg>
          <svg id="iC" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" style="display:none"><path d="M2 2l12 12M6.7 6.8A3 3 0 0011 11M4.2 4.4A7 7 0 001 8s2.5 5 7 5a7 7 0 003.8-1.2"/></svg>
        </button>
      </div>

      <!-- <div class="frow">
        <label class="rem"><input type="checkbox" name="remember"><span class="cb"></span>Remember me</label>
        <a href="#" class="fg">Forgot password?</a>
      </div> -->

      <button type="submit" class="sb" id="subbtn">
        <span class="btx">Log In</span>
        <div class="spi"><div class="ring"></div></div>
      </button>
    </form>

    <!-- <div class="form-foot">Don't have an account? <a href="setup.php">Create one</a></div> -->
  </div>

</div></div>

<script>
// Theme
const html=document.documentElement,thi=document.getElementById('thi');
function applyTheme(t){html.dataset.theme=t;thi.textContent=t==='dark'?'☀️':'🌙';}
document.getElementById('thm').onclick=()=>{
  const nt=html.dataset.theme==='dark'?'light':'dark';
  applyTheme(nt);localStorage.setItem('pos_theme',nt);
};
const sv=localStorage.getItem('pos_theme');if(sv)applyTheme(sv);

// Role
function sR(b){
  document.querySelectorAll('.rb').forEach(x=>x.classList.remove('on'));
  b.classList.add('on');document.getElementById('ri').value=b.dataset.role;bob();
}

// Bear
const bear=document.getElementById('bear'),pw=document.getElementById('pw');
let pv=false,pf=false;

function bob(){
  bear.classList.remove('hb');void bear.offsetWidth;
  bear.classList.add('hb');setTimeout(()=>bear.classList.remove('hb'),500);
}

function upBear(){
  bear.classList.remove('squinting','peeking','surprised');
  if(pv)                           bear.classList.add('peeking');   // can see password → arms up, blush
  else if(pf&&pw.value.length>0)   bear.classList.add('squinting'); // typing hidden → eyes shut
  else if(pf&&pw.value.length===0) bear.classList.add('surprised'); // focused, empty → big eyes
}

pw.addEventListener('focus',()=>{pf=true; upBear();bob();});
pw.addEventListener('blur', ()=>{pf=false;upBear();});
pw.addEventListener('input',upBear);

document.getElementById('tpb').addEventListener('click',()=>{
  pv=!pv;pw.type=pv?'text':'password';
  document.getElementById('iO').style.display=pv?'none':'';
  document.getElementById('iC').style.display=pv?'':'none';
  upBear();bob();
});

// Username → pupils follow
document.getElementById('un').addEventListener('input',function(){
  const sh=Math.min(this.value.length*.3,3);
  bear.querySelectorAll('.b-ep').forEach(p=>p.style.transform=`translateX(${sh}px)`);
});
document.getElementById('un').addEventListener('blur',()=>bear.querySelectorAll('.b-ep').forEach(p=>p.style.transform=''));
document.getElementById('un').addEventListener('focus',bob);

// Submit
const lf=document.getElementById('lf'),sb=document.getElementById('subbtn');
lf.addEventListener('submit',function(e){
  ['fu','fp'].forEach(x=>document.getElementById(x).classList.remove('err'));
  const u=document.getElementById('un').value.trim(),p=pw.value;
  if(!u||!p){
    e.preventDefault();
    if(!u)document.getElementById('fu').classList.add('err');
    if(!p)document.getElementById('fp').classList.add('err');
    let n=0,si=setInterval(()=>{
      bear.style.transform=n%2?'translateX(8px)':'translateX(-8px)';
      if(++n>5){clearInterval(si);bear.style.transform='';}
    },60);
    return;
  }
  sb.classList.add('ld');
});

// Ripple
sb.addEventListener('click',function(e){
  const r=this.getBoundingClientRect(),sp=document.createElement('span');
  sp.className='rip';const z=Math.max(r.width,r.height)*2;
  sp.style.cssText=`width:${z}px;height:${z}px;left:${e.clientX-r.left-z/2}px;top:${e.clientY-r.top-z/2}px`;
  this.appendChild(sp);setTimeout(()=>sp.remove(),600);
});
</script>
</body>
</html>