<?php
$db = new SQLite3(__DIR__ . '/flights.db');

$db->exec("
    CREATE TABLE IF NOT EXISTS flights (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_number TEXT NOT NULL,
        origin TEXT NOT NULL,
        destination TEXT NOT NULL,
        departure TEXT NOT NULL,
        arrival TEXT NOT NULL,
        seats_available INTEGER NOT NULL,
        price REAL NOT NULL
    );

    CREATE TABLE IF NOT EXISTS bookings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        flight_id INTEGER NOT NULL,
        passenger_name TEXT NOT NULL,
        passenger_email TEXT NOT NULL,
        seats INTEGER NOT NULL DEFAULT 1,
        booked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (flight_id) REFERENCES flights(id)
    );
");




$count = $db->querySingle("SELECT COUNT(*) FROM flights");
if ($count == 0) {
    $db->exec("
        INSERT INTO flights (flight_number, origin, destination, departure, arrival, seats_available, price) VALUES
        ('AE101', 'Dar es Salaam', 'Mwanza', '2026-06-15 06:00', '2026-06-15 08:00', 45, 250000.00),
        ('AE202', 'Mwanza', 'Arusha', '2026-06-15 10:30', '2026-06-15 13:00', 30, 300000.00),
        ('AE303', 'Dar es Salaam', 'Zanzibar', '2026-06-16 07:00', '2026-06-16 11:30', 60, 600000.00)
    ");
}




$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'book') {
        $flight_id = (int)($_POST['flight_id'] ?? 0);
        $name      = trim($_POST['passenger_name'] ?? '');
        $email     = trim($_POST['passenger_email'] ?? '');
        $seats     = max(1, (int)($_POST['seats'] ?? 1));

        if ($flight_id && $name && $email) {
            $flight = $db->querySingle("SELECT * FROM flights WHERE id = $flight_id", true);
            if ($flight && $flight['seats_available'] >= $seats) {
                $stmt = $db->prepare("INSERT INTO bookings (flight_id, passenger_name, passenger_email, seats) VALUES (:fid, :name, :email, :seats)");
                $stmt->bindValue(':fid', $flight_id);
                $stmt->bindValue(':name', $name);
                $stmt->bindValue(':email', $email);
                $stmt->bindValue(':seats', $seats);
                $stmt->execute();
                $here = $db->exec("UPDATE flights SET seats_available = seats_available - $seats WHERE id = $flight_id");

                if ($here)
                {
                  $message = "Booking confirmed! Flight {$flight['flight_number']} — {$flight['origin']} → {$flight['destination']} for $name.";
                  $messageType = 'success';
                }
                
            } else {
                $message = 'Not enough seats available or invalid flight.';
                $messageType = 'error';
            }
        } else {
            $message = 'Please fill in all fields.';
            $messageType = 'error';
        }
    }

    if ($action === 'cancel') {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $booking = $db->querySingle("SELECT * FROM bookings WHERE id = $booking_id", true);
        if ($booking) {
            $db->exec("UPDATE flights SET seats_available = seats_available + {$booking['seats']} WHERE id = {$booking['flight_id']}");
            $db->exec("DELETE FROM bookings WHERE id = $booking_id");
            $message = 'Booking cancelled and seats released.';
            $messageType = 'success';
        }
    }
}

// ─── DATA ─────────────────────────────────────────────────────────────────────
$flights  = $db->query("SELECT * FROM flights ORDER BY departure");
$bookings = $db->query("
    SELECT b.*, f.flight_number, f.origin, f.destination, f.departure, f.arrival, f.price
    FROM bookings b
    JOIN flights f ON b.flight_id = f.id
    ORDER BY b.booked_at DESC
");

$flightRows  = [];
while ($r = $flights->fetchArray(SQLITE3_ASSOC))  $flightRows[]  = $r;
$bookingRows = [];
while ($r = $bookings->fetchArray(SQLITE3_ASSOC)) $bookingRows[] = $r;
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Flight Booking</title>
<link rel="stylesheet" href="style.css">
</head>
<body>

<main>

  <?php if ($message): ?>
  <div class="toast <?= $messageType ?>">
    <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('flights',this)">Available Flights</button>
    <button class="tab-btn" onclick="switchTab('bookings',this)">My Bookings (<?= count($bookingRows) ?>)</button>
  </div>

  <!-- ── FLIGHTS TAB ── -->
  <div id="tab-flights" class="tab-panel active">
    <div class="section-label">Available Routes</div>
    <div class="flight-grid">
      <?php foreach ($flightRows as $f):
        $seats = (int)$f['seats_available'];
        $seatClass = $seats === 0 ? 'seats-none' : ($seats <= 10 ? 'seats-low' : 'seats-ok');
        $seatIcon  = $seats === 0 ? '✖' : ($seats <= 10 ? '⚡' : '✓');
        $dep = new DateTime($f['departure']);
        $arr = new DateTime($f['arrival']);
        $diff = $dep->diff($arr);
        $dur = ($diff->h ? $diff->h.'h ' : '') . ($diff->i ? $diff->i.'m' : '');
        $originCode = strtoupper(substr(preg_replace('/[^A-Za-z]/','',$f['origin']), 0, 3));
        $destCode   = strtoupper(substr(preg_replace('/[^A-Za-z]/','',$f['destination']), 0, 3));
      ?>
      <div class="flight-card">

<div class="card-top">
    <div>
        <div class="flight-num">
            <?= htmlspecialchars($f['flight_number']) ?>
        </div>
        <div class="flight-date">
            <?= $dep->format('d M Y') ?>
        </div>
    </div>

    <div class="flight-price-wrap">
        <span>Starting From</span>
        <div class="flight-price">
            TZS <?= number_format($f['price'], 2) ?>
        </div>
    </div>
</div>

<div class="flight-route">

    <div class="airport">
        <div class="airport-code">
            <?= $originCode ?>
        </div>
        <div class="airport-city">
            <?= htmlspecialchars($f['origin']) ?>
        </div>
        <div class="airport-time">
            <?= $dep->format('H:i') ?>
        </div>
    </div>

    <div class="route-center">
        <div class="route-duration">
            <?= $dur ?>
        </div>

        <div class="route-track">
            <span class="plane">✈</span>
        </div>

        <div class="route-type">
            DIRECT FLIGHT
        </div>
    </div>

    <div class="airport airport-right">
        <div class="airport-code">
            <?= $destCode ?>
        </div>
        <div class="airport-city">
            <?= htmlspecialchars($f['destination']) ?>
        </div>
        <div class="airport-time">
            <?= $arr->format('H:i') ?>
        </div>
    </div>

</div>

<div class="flight-footer">

    <div class="seat-info">
        <span class="seats-badge <?= $seatClass ?>">
            <?= $seatIcon ?>
            <?= $seats ?> Seats Left
        </span>
    </div>

    <div class="flight-meta">
        <?= $dep->format('d M') ?>
    </div>

</div>

<?php if ($seats > 0): ?>
    <button class="book-btn"
        onclick="openModal(
            <?= $f['id'] ?>,
            '<?= htmlspecialchars($f['flight_number']) ?>',
            '<?= htmlspecialchars($f['origin']) ?>',
            '<?= htmlspecialchars($f['destination']) ?>',
            <?= $f['price'] ?>,
            <?= $seats ?>
        )">
        BOOK NOW
    </button>
<?php else: ?>
    <button class="book-btn" disabled>
        SOLD OUT
    </button>
<?php endif; ?>

</div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ── BOOKINGS TAB ── -->
  <div id="tab-bookings" class="tab-panel">
    <div class="section-label">Confirmed Bookings</div>
    <?php if (empty($bookingRows)): ?>
    <div class="empty-state">
      <p>No bookings yet. Browse available flights and make your first booking!</p>
    </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Ref</th>
            <th>Flight</th>
            <th>Route</th>
            <th>Passenger</th>
            <th>Seats</th>
            <th>Total</th>
            <th>Departure</th>
            <th>Booked</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookingRows as $b):
            $dep = new DateTime($b['departure']);
            $booked = new DateTime($b['booked_at']);
          ?>
          <tr>
            <td><span class="ref-tag">#<?= str_pad($b['id'], 5, '0', STR_PAD_LEFT) ?></span></td>
            <td><strong><?= htmlspecialchars($b['flight_number']) ?></strong></td>
            <td class="route-tag"><?= htmlspecialchars($b['origin']) ?> → <?= htmlspecialchars($b['destination']) ?></td>
            <td>
              <div style="font-weight:600;"><?= htmlspecialchars($b['passenger_name']) ?></div>
              <div style="font-size:.75rem; color:var(--mist);"><?= htmlspecialchars($b['passenger_email']) ?></div>
            </td>
            <td><?= $b['seats'] ?></td>
            <td style="font-weight:600; color:var(--rust);">TZS <?= number_format($b['price'] * $b['seats'], 2) ?></td>
            <td style="font-family:'DM Mono',monospace; font-size:.78rem;"><?= $dep->format('d M Y H:i') ?></td>
            <td style="font-size:.78rem; color:var(--mist);"><?= $booked->format('d M H:i') ?></td>
            <td>
              <form method="POST" onsubmit="return confirm('Cancel booking #<?= $b['id'] ?>?')">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                <button class="cancel-btn" type="submit">Cancel</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

</main>

<!-- ── BOOKING MODAL ── -->
<div class="modal-overlay" id="bookingModal" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-header">
      <div>
        <div class="modal-title">Book Flight</div>
        <div class="modal-route" id="modalRoute">—</div>
      </div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <form method="POST" id="bookingForm">
        <input type="hidden" name="action" value="book">
        <input type="hidden" name="flight_id" id="modalFlightId">

        <div class="form-group">
          <label>Full Name</label>
          <input type="text" name="passenger_name" placeholder="e.g. Amina Hassan" required>
        </div>
        <div class="form-group">
          <label>Email Address</label>
          <input type="email" name="passenger_email" placeholder="amina@email.com" required>
        </div>
        <div class="form-group">
          <label>Number of Seats</label>
          <select name="seats" id="modalSeats" onchange="updateTotal()">
            <?php for ($i=1; $i<=9; $i++) echo "<option value='$i'>$i seat".($i>1?'s':'')."</option>"; ?>
          </select>
        </div>

        <div class="price-summary">
          <div>
            <div style="font-size:.72rem; color:var(--mist); letter-spacing:1px; text-transform:uppercase;">Total Price</div>
            <div style="font-size:.8rem; color:var(--steel);" id="priceBreakdown">1 seat × TZS 0.00</div>
          </div>
          <div class="price-total" id="priceTotal">TZS 0.00</div>
        </div>

        <button type="submit" class="submit-btn">Confirm Booking</button>
      </form>
    </div>
  </div>
</div>

<footer></footer>

<script>
  let currentPrice = 0, maxSeats = 9;

  function switchTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
  }

  function openModal(id, num, origin, dest, price, seats) {
    currentPrice = price;
    maxSeats = seats;
    document.getElementById('modalFlightId').value = id;
    document.getElementById('modalRoute').textContent = num + ' · ' + origin + ' → ' + dest;
    const sel = document.getElementById('modalSeats');
    sel.innerHTML = '';
    for (let i = 1; i <= Math.min(seats, 9); i++) {
      sel.innerHTML += `<option value="${i}">${i} seat${i>1?'s':''}</option>`;
    }
    updateTotal();
    document.getElementById('bookingModal').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    document.getElementById('bookingModal').classList.remove('open');
    document.body.style.overflow = '';
  }

  function updateTotal() {
    const s = parseInt(document.getElementById('modalSeats').value) || 1;
    const total = (s * currentPrice).toFixed(2);
    document.getElementById('priceTotal').textContent = 'TZS ' + total;
    document.getElementById('priceBreakdown').textContent = s + ' seat' + (s>1?'s':'') + ' × TZS' + currentPrice.toFixed(2);
  }
</script>
</body>
</html>