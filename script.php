now if i paste that other function on this script will be gone <!-- Leaflet JS for Map -->
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js"></script>

  <script>
    // Global map variables
    let map;
    let currentLayer = 'farms';
    let farmsLayer, livestockLayer, incidentsLayer;

    // Initialize map
    function initMap() {
      if (map) return; // Prevent multiple initializations
      
      map = L.map('map').setView([12.8797, 121.7740], 6); // Philippines center
      
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
      }).addTo(map);
      
      // Load initial farm data
      loadFarmData();
    }

    // Load farm data for map
    function loadFarmData() {
      const farms = <?php echo json_encode($all_farms); ?>;
      
      farmsLayer = L.layerGroup().clearLayers();
      farms.forEach(farm => {
        if (farm.latitude && farm.longitude) {
          const marker = L.marker([parseFloat(farm.latitude), parseFloat(farm.longitude)])
            .bindPopup(`
              <div style="min-width: 200px;">
                <h4 style="margin: 0 0 8px 0; color: var(--c-brand-dark);">${farm.name}</h4>
                <p><strong>Owner:</strong> ${farm.firstName || ''} ${farm.lastName || ''}</p>
                <p><strong>Livestock:</strong> ${farm.total_livestock || 0} heads</p>
                <p><strong>Status:</strong> <span style="color: var(--c-success);">${farm.status}</span></p>
              </div>
            `);
          farmsLayer.addLayer(marker);
        }
      });
      if (farmsLayer) farmsLayer.addTo(map);
    }

    function toggleSidebar() {
      document.getElementById('sidebar').classList.toggle('open');
      document.getElementById('overlay').classList.toggle('open');
    }

    function navTo(id, el) {
      document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
      document.getElementById('sec-' + id).classList.add('active');
      
      document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
      el.classList.add('active');
      
      document.getElementById('page-title').innerText = el.querySelector('span').innerText;
      
      if (window.innerWidth < 1024) toggleSidebar();
      
      // Initialize map when Geo-Monitoring is opened
      if (id === 'map') {
        setTimeout(() => {
          if (typeof initMap === 'function' && !map) {
            try {
              initMap();
            } catch (e) {
              console.error('Map initialization failed:', e);
            }
          }
        }, 300);
      }
    }

    // Search farms
    function searchFarms(query) {
      const rows = document.querySelectorAll('#farmsTable tbody tr');
      rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(query.toLowerCase()) ? '' : 'none';
      });
    }

    // Farm actions (AJAX simulation)
    function approveFarm(farmId) {
      if (confirm('Approve this farm?')) {
        alert('Farm approved! (API call would go here)');
        // Add real AJAX call here
      }
    }

    function rejectFarm(farmId) {
      if (confirm('Reject this farm?')) {
        alert('Farm rejected! (API call would go here)');
        // Add real AJAX call here
      }
    }

    function resolveIncident(incidentId) {
      if (confirm('Mark this incident as resolved?')) {
        alert('Incident resolved! (API call would go here)');
        // Add real AJAX call here
      }
    }

    // Map layer toggle - FIXED
    function toggleLayer(layerType, event) {
      if (!map) return;
      
      // Update toggle buttons
      document.querySelectorAll('.toggle-btn').forEach(btn => btn.classList.remove('active'));
      event.target.classList.add('active');
      
      currentLayer = layerType;
      
      // Toggle map layers
      if (layerType === 'farms') {
        if (farmsLayer) farmsLayer.addTo(map);
        if (livestockLayer) map.removeLayer(livestockLayer);
        if (incidentsLayer) map.removeLayer(incidentsLayer);
      } else if (layerType === 'livestock') {
        if (livestockLayer) livestockLayer.addTo(map);
        if (farmsLayer) map.removeLayer(farmsLayer);
        if (incidentsLayer) map.removeLayer(incidentsLayer);
        loadLivestockData();
      } else if (layerType === 'incidents') {
        if (incidentsLayer) incidentsLayer.addTo(map);
        if (farmsLayer) map.removeLayer(farmsLayer);
        if (livestockLayer) map.removeLayer(livestockLayer);
        loadIncidentsData();
      }
    }

    // ✅ FIXED Farm Map Functions
let mapLoaded = false;

async function loadFarmMap() {
    // Open fullscreen farm map with LIVE database data
    window.open('maps/farm-map.php', '_blank', 'noopener,noreferrer');
    showToast('Opening Interactive Farm Map...', 'success');
}

function fitBounds() {
    if (!map) return;
    map.fitBounds([
        [4.5, 116.9], 
        [21.2, 127.0]
    ]);
}

function showToast(message, type = 'info') {
    // Simple toast notification
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; top: 20px; right: 20px; 
        background: ${type === 'success' ? '#10B981' : '#3B82F6'}; 
        color: white; padding: 16px 24px; 
        border-radius: 12px; font-weight: 600; 
        z-index: 9999; transform: translateX(400px); 
        transition: transform 0.3s ease;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    
    setTimeout(() => toast.style.transform = 'translateX(0)', 100);
    setTimeout(() => {
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 3000);
}

    function loadLivestockData() {
      // Mock livestock data - replace with real AJAX call
      console.log('Loading livestock layer...');
      livestockLayer = L.layerGroup().clearLayers();
      
      // Add some sample livestock markers
      const livestockPoints = [
        [14.5995, 120.9842, 25], // Manila
        [10.3157, 123.8854, 45], // Cebu
        [8.4817, 124.6463, 8]    // Cagayan de Oro
      ];
      
      livestockPoints.forEach(([lat, lng, count]) => {
        const color = count > 50 ? '#ef4444' : count > 10 ? '#f59e0b' : '#10b981';
        const marker = L.circleMarker([lat, lng], {
          radius: Math.max(8, count / 5),
          fillColor: color,
          color: '#000',
          weight: 2,
          opacity: 1,
          fillOpacity: 0.7
        }).bindPopup(`Livestock: ${count} heads`);
        livestockLayer.addLayer(marker);
      });
      
      livestockLayer.addTo(map);
    }

    function loadIncidentsData() {
      // Mock incidents data
      console.log('Loading incidents layer...');
      incidentsLayer = L.layerGroup().clearLayers();
      
      const incidents = <?php echo json_encode($all_incidents); ?>;
      incidents.forEach(incident => {
        if (incident.latitude && incident.longitude) {
          const marker = L.marker([parseFloat(incident.latitude), parseFloat(incident.longitude)])
            .bindPopup(`<b>${incident.title}</b><br>Priority: ${incident.priority}`);
          incidentsLayer.addLayer(marker);
        }
      });
      
      incidentsLayer.addTo(map);
    }

    // Reports & Analytics Functions
function downloadReport(format, type = 'complete') {
  const types = {
    complete: 'Complete System Report',
    farms: 'Farm Analytics',
    livestock: 'Livestock Inventory', 
    incidents: 'Incident Reports'
  };
  
  showLoading(true);
  
  // Real AJAX call to generate and download report
  fetch('api/generate_report.php', {
    method: 'POST',
    headers: { 
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify({
      format: format,
      report_type: type,
      user_id: <?php echo $user_id; ?>
    })
  })
  .then(response => response.blob())
  .then(blob => {
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `AgriTrace_${types[type] || 'Report'}_${new Date().toISOString().slice(0,10)}.${format}`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.URL.revokeObjectURL(url);
    showToast(`📥 ${types[type]} downloaded as ${format.toUpperCase()}!`, 'success');
  })
  .catch(error => {
    console.error('Download failed:', error);
    showToast('❌ Download failed. Please try again.', 'error');
  })
  .finally(() => showLoading(false));
}

function showAnalyticsChart() {
  showToast('📊 Chart view coming soon! Use Excel/CSV for detailed analysis.', 'info');
  // Future: Integrate Chart.js for live charts
}

function showLoading(show = true) {
  const overlay = document.getElementById('loadingOverlay');
  if (overlay) {
    overlay.style.display = show ? 'flex' : 'none';
  }
}

    // Profile update - FIXED
   let map, currentLayer;

// Profile Modal Functions - SIMPLIFIED
function openEditProfile() {
    document.getElementById('editProfileModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Pre-fill form with current data
    const profileData = <?php echo json_encode($user_profile); ?>;
    const form = document.getElementById('editProfileForm');
    Object.keys(profileData).forEach(key => {
        const input = form.querySelector(`[name="${key}"]`);
        if (input && profileData[key]) input.value = profileData[key];
    });
    
    // Set dropdown displays
    document.getElementById('regionSearch').value = profileData.assigned_region || '';
    document.getElementById('provinceSearch').value = profileData.province || '';
    document.getElementById('municipalitySearch').value = profileData.municipality || '';
}

function closeEditProfile() {
    document.getElementById('editProfileModal').style.display = 'none';
    document.body.style.overflow = '';
}

// Profile Picture Preview
document.addEventListener('DOMContentLoaded', function() {
    const profilePicInput = document.getElementById('profilePicInput');
    const preview = document.getElementById('editProfilePreview');
    
    if (profilePicInput) {
        profilePicInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.style.display = 'block';
                };
                reader.readAsDataURL(file);
            }
        });
    }
    
    // Form submission - SINGLE HANDLER
    const editForm = document.getElementById('editProfileForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            e.preventDefault();
            submitProfileForm();
        });
    }
});

// SINGLE FORM SUBMISSION FUNCTION
function submitProfileForm() {
    const formData = new FormData(document.getElementById('editProfileForm'));
    formData.append('user_id', <?php echo $user_id; ?>);
    formData.append('action', 'update_profile');
    
    showLoading(true);
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showToast('✅ Profile updated successfully!', 'success');
            closeEditProfile();
            setTimeout(() => location.reload(), 1500);
        } else {
            showToast('❌ ' + (data.message || 'Update failed'), 'error');
        }
    })
    .catch(error => {
        showToast('❌ Network error. Please try again.', 'error');
        console.error('Error:', error);
    })
    .finally(() => showLoading(false));
}

// Toast & Loading
function showToast(message, type = 'info') {
    const toast = document.createElement('div');
    toast.style.cssText = `
        position: fixed; top: 90px; right: 20px; 
        background: ${type === 'success' ? '#10B981' : type === 'error' ? '#EF4444' : '#3B82F6'}; 
        color: white; padding: 16px 24px; border-radius: 12px; font-weight: 600; 
        z-index: 9999; transform: translateX(400px); transition: transform 0.3s;
        box-shadow: 0 10px 30px rgba(0,0,0,0.2); font-family: 'DM Sans', sans-serif;
    `;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => toast.style.transform = 'translateX(0)', 100);
    setTimeout(() => {
        toast.style.transform = 'translateX(400px)';
        setTimeout(() => document.body.removeChild(toast), 300);
    }, 4000);
}

function showLoading(show = true) {
    document.getElementById('loadingOverlay').style.display = show ? 'flex' : 'none';
}

// Keep existing nav/map functions (everything else stays the same)
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('overlay').classList.toggle('open');
}

function navTo(id, el) {
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById('sec-' + id).classList.add('active');
    
    document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
    el.classList.add('active');
    
    document.getElementById('page-title').innerText = el.querySelector('span').innerText;
    
    if (window.innerWidth < 1024) toggleSidebar();
}

// PH Location Data
const phRegions = [
  "Region I – Ilocos Region", "Region II – Cagayan Valley", "Region III – Central Luzon",
  "Region IV‑A – CALABARZON", "MIMAROPA Region", "Region V – Bicol Region",
  "Region VI – Western Visayas", "Region VII – Central Visayas", "Region VIII – Eastern Visayas",
  "Region IX – Zamboanga Peninsula", "Region X – Northern Mindanao", "Region XI – Davao Region",
  "Region XII – SOCCSKSARGEN", "Region XIII – Caraga", "NCR – National Capital Region",
  "CAR – Cordillera Administrative Region", "BARMM – Bangsamoro Autonomous Region in Muslim Mindanao",
  "NIR – Negros Island Region"
];

const phProvinces = [
  "Abra", "Agusan del Norte", "Agusan del Sur", "Aklan", "Albay", "Antique", "Apayao", "Aurora",
  "Basilan", "Bataan", "Batanes", "Batangas", "Benguet", "Biliran", "Bohol", "Bukidnon",
  "Bulacan", "Cagayan", "Camarines Norte", "Camarines Sur", "Camiguin", "Capiz",
  "Catanduanes", "Cavite", "Cebu", "Cotabato", "Davao de Oro (Compostela Valley)",
  "Davao del Norte", "Davao del Sur", "Davao Occidental",   "Davao de Oro (Compostela Valley)", "Davao del Norte", "Davao del Sur", "Davao Occidental", "Davao Oriental",
  "Dinagat Islands", "Eastern Samar", "Guimaras", "Ifugao", "Ilocos Norte", "Ilocos Sur", "Iloilo",
  "Isabela", "Kalinga", "La Union", "Laguna", "Lanao del Norte", "Lanao del Sur", "Leyte",
  "Maguindanao del Norte", "Maguindanao del Sur", "Marinduque", "Masbate", "Misamis Occidental",
    "Misamis Oriental", "Mountain Province", "Negros Occidental", "Negros Oriental", "Northern Samar",
  "Nueva Ecija", "Nueva Vizcaya", "Occidental Mindoro", "Oriental Mindoro", "Palawan",
  "Pampanga", "Pangasinan", "Quezon", "Quirino", "Rizal", "Romblon", "Samar", "Sarangani",
  "Siquijor", "Sorsogon", "South Cotabato", "Southern Leyte", "Sultan Kudarat", "Sulu",
  "Surigao del Norte",   "Surigao del Sur", "Tarlac", "Tawi-Tawi", "Zambales", "Zamboanga del Norte",
  "Zamboanga del Sur", "Zamboanga Sibugay"
];

const phMunicipalitiesByProvince = {
  "Abra": ["Bangued", "Boliney", "Bucay", "Bucloc", "Daguioman", "Danglas", "Dolores", "La Paz", "Lacub", "Lagangilang", "Lagayan", "Langiden", "Licuan-Baay", "Luba", "Malibcong", "Manabo", "Peñarrubia", "Pidigan", "Pilar", "Sallapadan", "San Isidro", "San Juan", "San Quintin", "Tayum", "Tineg", "Tubo", "Villaviciosa"],
  "Agusan del Norte": ["Buenavista", "Carmen", "Jabonga", "Kitcharao", "Las Nieves", "Magallanes", "Nasipit", "Remedios T. Romualdez", "Santiago", "Tubay"],
  "Agusan del Sur": ["Bunawan", "Esperanza", "La Paz", "Loreto", "Prosperidad", "Rosario", "San Francisco", "San Luis", "Santa Josefa", "Sibagat", "Talacogon", "Trento", "Veruela"],
  "Aklan": ["Altavas", "Balete", "Banga", "Batan", "Buruanga", "Ibajay", "Kalibo", "Lezo", "Libacao", "Madalag", "Makato", "Malay", "Malinao", "Nabas", "New Washington", "Numancia", "Tangalan"],
  "Albay": ["Bacacay", "Camalig", "Daraga", "Guinobatan", "Jovellar", "Libon", "Malilipot", "Malinao", "Manito", "Oas", "Pio Duran", "Polangui", "Rapu-Rapu", "Santo Domingo", "Tiwi"],
  "Antique": ["Anini-y", "Barbaza", "Belison", "Bugasong", "Caluya", "Culasi", "Hamtic", "Laua-an", "Libertad", "Pandan", "Patnongon", "San Jose de Buenavista", "San Remigio", "Sebaste", "Sibalom", "Tibiao", "Tobias Fornier", "Valderrama"],
  "Apayao": ["Calanasan", "Conner", "Flora", "Kabugao", "Luna", "Pudtol", "Santa Marcela"],
  "Aurora": ["Baler", "Casiguran", "Dilasag", "Dinalungan", "Dingalan", "Dipaculao", "Maria Aurora", "San Luis"],
  "Basilan": ["Akbar", "Al-Barka", "Hadji Mohammad Ajul", "Hadji Muhtamad", "Lantawan", "Maluso", "Sumisip", "Tabuan-Lasa", "Tipo-Tipo", "Tuburan", "Ungkaya Pukan"],
  "Bataan": ["Abucay", "Bagac", "Dinalupihan", "Hermosa", "Limay", "Mariveles", "Morong", "Orani", "Orion", "Pilar", "Samal"],
  "Batanes": ["Basco", "Itbayat", "Ivana", "Mahatao", "Sabtang", "Uyugan"],
  "Batangas": ["Agoncillo", "Alitagtag", "Balayan", "Balete", "Bauan", "Calaca", "Calatagan", "Cuenca", "Ibaan", "Laurel", "Lemery", "Lian", "Lobo", "Mabini", "Malvar", "Mataasnakahoy", "Nasugbu", "Padre Garcia", "Rosario", "San Jose", "San Juan", "San Luis", "San Nicolas", "San Pascual", "Santa Teresita", "Taal", "Talisay", "Taysan", "Tingloy", "Tuy"],
  "Benguet": ["Atok", "Bakun", "Bokod", "Buguias", "Itogon", "Kabayan", "Kapangan", "Kibungan", "La Trinidad", "Mankayan", "Sablan", "Tuba", "Tublay"],
  "Biliran": ["Almeria", "Biliran", "Cabucgayan", "Caibiran", "Culaba", "Kawayan", "Maripipi", "Naval"],
  "Bohol": ["Alburquerque", "Alicia", "Anda", "Antequera", "Baclayon", "Balilihan", "Batuan", "Bien Unido", "Bilar", "Buenavista", "Calape", "Candijay", "Carmen", "Catigbian", "Clarin", "Corella", "Cortes", "Dagohoy", "Danao", "Dauis", "Dimiao", "Duero", "Garcia Hernandez", "Getafe", "Guindulman", "Inabanga", "Jagna", "Lila", "Loay", "Loboc", "Loon", "Mabini", "Maribojoc", "Panglao", "Pilar", "President Carlos P. Garcia", "Sagbayan", "San Isidro", "San Miguel", "Sevilla", "Sierra Bullones", "Sikatuna", "Talibon", "Trinidad", "Tubigon", "Ubay", "Valencia"],
  "Bukidnon": ["Baungon", "Cabanglasan", "Damulog", "Dangcagan", "Don Carlos", "Impasugong", "Kadingilan", "Kalilangan", "Kibawe", "Kitaotao", "Lantapan", "Libona", "Malitbog", "Manolo Fortich", "Maramag", "Pangantucan", "Quezon", "San Fernando", "Sumilao", "Talakag"],
  "Bulacan": ["Angat", "Balagtas", "Baliuag", "Bocaue", "Bulakan", "Bustos", "Calumpit", "Doña Remedios Trinidad", "Guiguinto", "Hagonoy", "Marilao", "Norzagaray", "Obando", "Pandi", "Paombong", "Plaridel", "Pulilan", "San Ildefonso", "San Miguel", "San Rafael", "Santa Maria"],
  "Cagayan": ["Abulug", "Alcala", "Allacapan", "Amulung", "Aparri", "Baggao", "Ballesteros", "Buguey", "Calayan", "Camalaniugan", "Claveria", "Enrile", "Gattaran", "Gonzaga", "Iguig", "Lal-lo", "Lasam", "Pamplona", "Peñablanca", "Piat", "Rizal", "Sanchez-Mira", "Santa Ana", "Santa Praxedes", "Santa Teresita", "Santo Niño", "Solana", "Tuao"],
  "Camarines Norte": ["Basud", "Capalonga", "Daet", "Jose Panganiban", "Labo", "Mercedes", "Paracale", "San Lorenzo Ruiz", "San Vicente", "Santa Elena", "Talisay", "Vinzons"],
  "Camarines Sur": ["Baao", "Balatan", "Bato", "Bombon", "Buhi", "Bula", "Cabusao", "Calabanga", "Camaligan", "Canaman", "Caramoan", "Del Gallego", "Gainza", "Garchitorena", "Goa", "Lagonoy", "Libmanan", "Lupi", "Magarao", "Milaor", "Minalabac", "Nabua", "Ocampo", "Pamplona", "Pasacao", "Pili", "Presentacion", "Ragay", "Sagñay", "San Fernando", "San Jose", "Sipocot", "Siruma", "Tigaon", "Tinambac"],
  "Camiguin": ["Catarman", "Guinsiliban", "Mahinog", "Mambajao", "Sagay"],
  "Capiz": ["Cuartero", "Dao", "Dumalag", "Dumarao", "Ivisan", "Jamindan", "Maayon", "Mambusao", "Panay", "Panitan", "Pilar", "Pontevedra", "President Roxas", "Sapian", "Sigma", "Tapaz"],
  "Catanduanes": ["Bagamanoc", "Baras", "Bato", "Caramoran", "Gigmoto", "Pandan", "Panganiban", "San Andres", "San Miguel", "Viga", "Virac"],
  "Cavite": ["Alfonso", "Amadeo", "Carmona", "General Emilio Aguinaldo", "General Mariano Alvarez", "Indang", "Kawit", "Magallanes", "Maragondon", "Mendez", "Naic", "Noveleta", "Rosario", "Silang", "Tanza", "Ternate"],
  "Cebu": ["Alcantara", "Alcoy", "Alegria", "Aloguinsan", "Argao", "Asturias", "Badian", "Balamban", "Bantayan", "Barili", "Boljoon", "Borbon", "Carmen", "Catmon", "Compostela", "Consolacion", "Cordova", "Daanbantayan", "Dalaguete", "Dumanjug", "Ginatilan", "Liloan", "Madridejos", "Malabuyoc", "Medellin", "Minglanilla", "Moalboal", "Oslob", "Pilar", "Pinamungajan", "Poro", "Ronda", "Samboan", "San Fernando", "San Francisco", "San Remigio", "Santa Fe", "Santander", "Sibonga", "Sogod", "Tabogon", "Tabuelan", "Tuburan", "Tudela"],
  "Cotabato": ["Alamada", "Aleosan", "Antipas", "Arakan", "Banisilan", "Carmen", "Kabacan", "Libungan", "M'lang", "Magpet", "Makilala", "Matalam", "Midsayap", "Pigcawayan", "Pikit", "President Roxas", "Tulunan"],
  "Davao de Oro (Compostela Valley)": ["Compostela", "Laak", "Mabini", "Maco", "Maragusan", "Mawab", "Monkayo", "Montevista", "Nabunturan", "New Bataan", "Pantukan"],
  "Davao del Norte": ["Asuncion", "Braulio E. Dujali", "Carmen", "Kapalong", "New Corella", "San Isidro", "Santo Tomas", "Talaingod"],
  "Davao del Sur": ["Bansalan", "Hagonoy", "Kiblawan", "Magsaysay", "Malalag", "Matanao", "Padada", "Santa Cruz", "Sulop"],
  "Davao Occidental": ["Don Marcelino", "Jose Abad Santos", "Malita", "Santa Maria", "Sarangani"],
  "Davao Oriental": ["Baganga", "Banaybanay", "Boston", "Caraga", "Cateel", "Governor Generoso", "Lupon", "Manay", "San Isidro", "Tarragona"],
  "Dinagat Islands": ["Basilisa", "Cagdianao", "Dinagat", "Libjo", "Loreto", "San Jose", "Tubajon"],
  "Eastern Samar": ["Arteche", "Balangiga", "Balangkayan", "Can-avid", "Dolores", "General MacArthur", "Giporlos", "Guiuan", "Hernani", "Jipapad", "Lawaan", "Llorente", "Maslog", "Maydolong", "Mercedes", "Oras", "Quinapondan", "Salcedo", "San Julian", "San Policarpo", "Sulat", "Taft"],
  "Guimaras": ["Buenavista", "Jordan", "Nueva Valencia", "San Lorenzo", "Sibunag"],
  "Ifugao": ["Aguinaldo", "Alfonso Lista", "Asipulo", "Banaue", "Hingyon", "Hungduan", "Kiangan", "Lagawe", "Lamut", "Mayoyao", "Tinoc"],
  "Ilocos Norte": ["Adams", "Bacarra", "Badoc", "Bangui", "Banna", "Burgos", "Carasi", "Currimao", "Dingras", "Dumalneg", "Marcos", "Nueva Era", "Pagudpud", "Paoay", "Pasuquin", "Piddig", "Pinili", "San Nicolas", "Sarrat", "Solsona", "Vintar"],
  "Ilocos Sur": ["Alilem", "Banayoyo", "Bantay", "Burgos", "Cabugao", "Caoayan", "Cervantes", "Galimuyod", "Gregorio del Pilar", "Lidlidda", "Magsingal", "Nagbukel", "Narvacan", "Quirino", "Salcedo", "San Emilio", "San Esteban", "San Ildefonso", "San Juan", "San Vicente", "Santa", "Santa Catalina", "Santa Cruz", "Santa Lucia", "Santa Maria", "Santiago", "Santo Domingo", "Sigay", "Sinait", "Sugpon", "Suyo", "Tagudin"],
  "Iloilo": ["Ajuy", "Alimodian", "Anilao", "Badiangan", "Balasan", "Banate", "Barotac Nuevo", "Barotac Viejo", "Batad", "Bingawan", "Cabatuan", "Calinog", "Carles", "Concepcion", "Dingle", "Dueñas", "Dumangas", "Estancia", "Guimbal", "Igbaras", "Janiuay", "Lambunao", "Leganes", "Lemery", "Leon", "Maasin", "Miagao", "Mina", "New Lucena", "Oton", "Pavia", "Pototan", "San Dionisio", "San Enrique", "San Joaquin", "San Miguel", "San Rafael", "Santa Barbara", "Sara", "Tigbauan", "Tubungan", "Zarraga"],
    "Isabela": ["Alicia", "Angadanan", "Aurora", "Benito Soliven", "Burgos", "Cabagan", "Cabatuan", "Cordon", "Delfin Albano", "Dinapigue", "Divilacan", "Echague", "Gamu", "Jones", "Luna", "Maconacon", "Mallig", "Naguilian", "Palanan", "Quezon", "Quirino", "Ramon", "Reina Mercedes", "Roxas", "San Agustin", "San Guillermo", "San Isidro", "San Manuel", "San Mariano", "San Mateo", "San Pablo", "Santa Maria", "Santo Tomas", "Tumauini"],
  "Kalinga": ["Balbalan", "Lubuagan", "Pasil", "Pinukpuk", "Rizal", "Tanudan", "Tinglayan"],
  "La Union": ["Agoo", "Aringay", "Bacnotan", "Bagulin", "Balaoan", "Bangar", "Bauang", "Burgos", "Caba", "Luna", "Naguilian", "Pugo", "Rosario", "San Gabriel", "San Juan", "Santo Tomas", "Santol", "Sudipen", "Tubao"],
  "Laguna": ["Alaminos", "Bay", "Calauan", "Cavinti", "Famy", "Kalayaan", "Liliw", "Los Baños", "Luisiana", "Lumban", "Mabitac", "Magdalena", "Majayjay", "Nagcarlan", "Paete", "Pagsanjan", "Pakil", "Pangil", "Pila", "Rizal", "Santa Cruz", "Santa Maria", "Siniloan", "Victoria"],
  "Lanao del Norte": ["Bacolod", "Baloi", "Baroy", "Kapatagan", "Kauswagan", "Kolambugan", "Lala", "Linamon", "Magsaysay", "Maigo", "Matungao", "Munai", "Nunungan", "Pantao Ragat", "Pantar", "Poona Piagapo", "Salvador", "Sapad", "Sultan Naga Dimaporo", "Tagoloan", "Tangcal", "Tubod"],
  "Lanao del Sur": ["Amai Manabilang", "Bacolod-Kalawi", "Balabagan", "Balindong", "Bayang", "Binidayan", "Buadiposo-Buntong", "Bubong", "Butig", "Calanogas", "Ditsaan-Ramain", "Ganassi", "Kapai", "Kapatagan", "Lumba-Bayabao", "Lumbaca-Unayan", "Lumbatan", "Lumbayanague", "Madalum", "Madamba", "Maguing", "Malabang", "Marantao", "Marogong", "Masiu", "Mulondo", "Pagayawan", "Piagapo", "Picong", "Poona Bayabao", "Pualas", "Saguiaran", "Sultan Dumalondong", "Tagoloan II", "Tamparan", "Taraka", "Tubaran", "Tugaya", "Wao"],
  "Leyte": ["Abuyog", "Alangalang", "Albuera", "Babatngon", "Barugo", "Bato", "Burauen", "Calubian", "Capoocan", "Carigara", "Dagami", "Dulag", "Hilongos", "Hindang", "Inopacan", "Isabel", "Jaro", "Javier", "Julita", "Kananga", "La Paz", "Leyte", "MacArthur", "Mahaplag", "Matag-ob", "Matalom", "Mayorga", "Merida", "Palo", "Palompon", "Pastrana", "San Isidro", "San Miguel", "Santa Fe", "Tabango", "Tabontabon", "Tanauan", "Tolosa", "Tunga", "Villaba"],
  "Maguindanao del Norte": ["Datu Odin Sinsuat", "Datu Blah T. Sinsuat", "Datu Salibo", "Datu Montawal", "Northern Kabuntalan", "Sultan Mastura", "Matanog"],
  "Maguindanao del Sur": ["Ampatuan", "Buluan", "Datu Abdullah Sangki", "Datu Anggal Midtimbang", "Datu Hoffer Ampatuan", "Datu Paglas", "Datu Piang", "Datu Saudi-Ampatuan", "Datu Unsay", "General Salipada K. Pendatun", "Guindulungan", "Kabuntalan", "Mamasapano", "Mangudadatu", "Pagalungan", "Paglat", "Pandag", "Parang", "Rajah Buayan", "Shariff Aguak", "Shariff Saydona Mustapha", "South Upi", "Sultan Kudarat", "Sultan Sumagka", "Talayan", "Upi"],
  "Marinduque": ["Boac", "Buenavista", "Gasan", "Mogpog", "Santa Cruz", "Torrijos"],
  "Masbate": ["Aroroy", "Baleno", "Balud", "Batuan", "Cataingan", "Cawayan", "Claveria", "Dimasalang", "Esperanza", "Mandaon", "Milagros", "Mobo", "Monreal", "Palanas", "Pio V. Corpuz", "Placer", "San Fernando", "San Jacinto", "San Pascual", "Uson"],
  "Misamis Occidental": ["Aloran", "Baliangao", "Bonifacio", "Calamba", "Clarin", "Concepcion", "Don Victoriano Chiongbian", "Jimenez", "Lopez Jaena", "Panaon", "Plaridel", "Sapang Dalaga", "Sinacaban", "Tudela"],
  "Misamis Oriental": ["Alubijid", "Balingasag", "Balingoan", "Binuangan", "Claveria", "Gitagum", "Initao", "Jasaan", "Kinoguitan", "Lagonglong", "Laguindingan", "Libertad", "Lugait", "Magsaysay", "Manticao", "Medina", "Naawan", "Opol", "Salay", "Sugbongcogon", "Tagoloan", "Talisayan", "Villanueva"],
  "Mountain Province": ["Barlig", "Bauko", "Besao", "Bontoc", "Natonin", "Paracelis", "Sabangan", "Sadanga", "Sagada", "Tadian"],
  "Negros Occidental": ["Binalbagan", "Calatrava", "Candoni", "Cauayan", "Enrique B. Magalona", "Hinigaran", "Hinoba-an", "Ilog", "Isabela", "La Castellana", "Manapla", "Moises Padilla", "Murcia", "Pontevedra", "Pulupandan", "Salvador Benedicto", "San Enrique", "Toboso", "Valladolid"],
  "Negros Oriental": ["Amlan", "Ayungon", "Bacong", "Basay", "Bindoy", "Dauin", "Jimalalud", "La Libertad", "Mabinay", "Manjuyod", "Pamplona", "San Jose", "Santa Catalina", "Siaton", "Sibulan", "Tayasan", "Valencia", "Vallehermoso", "Zamboanguita"],
  "Northern Samar": ["Allen", "Biri", "Bobon", "Capul", "Catarman", "Catubig", "Gamay", "Laoang", "Lapinig", "Las Navas", "Lavezares", "Lope de Vega", "Mapanas", "Mondragon", "Palapag", "Pambujan", "Rosario", "San Antonio", "San Isidro", "San Jose", "San Roque", "San Vicente", "Silvino Lobos", "Victoria"],
  "Nueva Ecija": ["Aliaga", "Bongabon", "Cabiao", "Carranglan", "Cuyapo", "Gabaldon", "General Mamerto Natividad", "General Tinio", "Guimba", "Jaen", "Laur", "Licab", "Llanera", "Lupao", "Nampicuan", "Pantabangan", "Peñaranda", "Quezon", "Rizal", "San Antonio", "San Isidro", "San Leonardo", "Santa Rosa", "Santo Domingo", "Talavera", "Talugtug", "Zaragoza"],
  "Nueva Vizcaya": ["Alfonso Castañeda", "Ambaguio", "Aritao", "Bagabag", "Bambang", "Bayombong", "Diadi", "Dupax del Norte", "Dupax del Sur", "Kasibu", "Kayapa", "Quezon", "Santa Fe", "Solano", "Villaverde"],
  "Occidental Mindoro": ["Abra de Ilog", "Calintaan", "Looc", "Lubang", "Magsaysay", "Mamburao", "Paluan", "Rizal", "Sablayan", "San Jose", "Santa Cruz"],
  "Oriental Mindoro": ["Baco", "Bansud", "Bongabong", "Bulalacao", "Gloria", "Mansalay", "Naujan", "Pinamalayan", "Pola", "Puerto Galera", "Roxas", "San Teodoro", "Socorro", "Victoria"],
  "Palawan": ["Aborlan", "Agutaya", "Araceli", "Balabac", "Bataraza", "Brooke's Point", "Busuanga", "Cagayancillo", "Coron", "Culion", "Cuyo", "Dumaran", "El Nido", "Kalayaan", "Linapacan", "Magsaysay", "Narra", "Quezon", "Rizal", "Roxas", "San Vicente", "Sofronio Española", "Taytay"],
  "Pampanga": ["Apalit", "Arayat", "Bacolor", "Candaba", "Floridablanca", "Guagua", "Lubao", "Macabebe", "Magalang", "Masantol", "Mexico", "Minalin", "Porac", "San Luis", "San Simon", "Santa Ana", "Santa Rita", "Santo Tomas", "Sasmuan"],
  "Pangasinan": ["Agno", "Aguilar", "Alcala", "Anda", "Asingan", "Balungao", "Bani", "Basista", "Bautista", "Bayambang", "Binalonan", "Binmaley", "Bolinao", "Bugallon", "Burgos", "Calasiao", "Dasol", "Infanta", "Labrador", "Laoac", "Lingayen", "Mabini", "Malasiqui", "Manaoag", "Mangaldan", "Mangatarem", "Mapandan", "Natividad", "Pozorrubio", "Rosales", "San Fabian", "San Jacinto", "San Manuel", "San Nicolas", "San Quintin", "Santa Barbara", "Santa Maria", "Santo Tomas", "Sison", "Sual", "Tayug", "Umingan", "Urbiztondo", "Villasis"],
  "Quezon": ["Agdangan", "Alabat", "Atimonan", "Buenavista", "Burdeos", "Calauag", "Candelaria", "Catanauan", "Dolores", "General Luna", "General Nakar", "Guinayangan", "Gumaca", "Infanta", "Jomalig", "Lopez", "Lucban", "Macalelon", "Mauban", "Mulanay", "Padre Burgos", "Pagbilao", "Panukulan", "Patnanungan", "Perez", "Pitogo", "Plaridel", "Polillo", "Quezon", "Real", "Sampaloc", "San Andres", "San Antonio", "San Francisco", "San Narciso", "Sariaya", "Tagkawayan", "Tiaong", "Unisan"],
  "Quirino": ["Aglipay", "Cabarroguis", "Diffun", "Maddela", "Nagtipunan", "Saguday"],
  "Rizal": ["Angono", "Baras", "Binangonan", "Cainta", "Cardona", "Jalajala", "Morong", "Pililla", "Rodriguez", "San Mateo", "Tanay", "Taytay", "Teresa"],
  "Romblon": ["Alcantara", "Banton", "Cajidiocan", "Calatrava", "Concepcion", "Corcuera", "Ferrol", "Looc", "Magdiwang", "Odiongan", "Romblon", "San Agustin", "San Andres", "San Fernando", "San Jose", "Santa Fe", "Santa Maria"],
  "Samar": ["Almagro", "Basey", "Calbiga", "Daram", "Gandara", "Hinabangan", "Jiabong", "Marabut", "Matuguinao", "Motiong", "Pagsanghan", "Paranas", "Pinabacdao", "San Jorge", "San Jose de Buan", "San Sebastian", "Santa Margarita", "Santa Rita", "Santo Niño", "Tagapul-an", "Talalora", "Tarangnan", "Villareal", "Zumarraga"],
  "Sarangani": ["Alabel", "Glan", "Kiamba", "Maasim", "Maitum", "Malapatan", "Malungon"],
  "Siquijor": ["Enrique Villanueva", "Larena", "Lazi", "Maria", "San Juan", "Siquijor"],
  "Sorsogon": ["Barcelona", "Bulan", "Bulusan", "Casiguran", "Castilla", "Donsol", "Gubat", "Irosin", "Juban", "Magallanes", "Matnog", "Pilar", "Prieto Diaz", "Santa Magdalena"],
  "South Cotabato": ["Banga", "Lake Sebu", "Norala", "Polomolok", "Santo Niño", "Surallah", "T'Boli", "Tampakan", "Tantangan", "Tupi"],
  "Southern Leyte": ["Anahawan", "Bontoc", "Hinunangan", "Hinundayan", "Libagon", "Liloan", "Limasawa", "Macrohon", "Malitbog", "Padre Burgos", "Pintuyan", "Saint Bernard", "San Francisco", "San Juan", "San Ricardo", "Silago", "Sogod", "Tomas Oppus"],
  "Sultan Kudarat": ["Bagumbayan", "Columbio", "Esperanza", "Isulan", "Kalamansig", "Lambayong", "Lebak", "Lutayan", "Palimbang", "President Quirino", "Senator Ninoy Aquino"],
  "Sulu": ["Banguingui", "Hadji Panglima Tahil", "Indanan", "Jolo", "Kalingalan Caluang", "Lugus", "Luuk", "Maimbung", "Old Panamao", "Omar", "Pandami", "Panglima Estino", "Pangutaran", "Parang", "Pata", "Patikul", "Siasi", "Talipao", "Tapul"],
  "Surigao del Norte": ["Alegria", "Bacuag", "Burgos", "Claver", "Dapa", "Del Carmen", "General Luna", "Gigaquit", "Mainit", "Malimono", "Pilar", "Placer", "San Benito", "San Francisco", "San Isidro", "Santa Monica", "Sison", "Socorro", "Tagana-an", "Tubod"],
  "Surigao del Sur": ["Barobo", "Bayabas", "Cagwait", "Cantilan", "Carmen", "Carrascal", "Cortes", "Hinatuan", "Lanuza", "Lianga", "Lingig", "Madrid", "Marihatag", "San Agustin", "San Miguel", "Tagbina", "Tago"],
  "Tarlac": ["Anao", "Bamban", "Camiling", "Capas", "Concepcion", "Gerona", "La Paz", "Mayantoc", "Moncada", "Paniqui", "Pura", "Ramos", "San Clemente", "San Jose", "San Manuel", "Santa Ignacia", "Victoria"],
  "Tawi-Tawi": ["Bongao", "Languyan", "Mapun", "Panglima Sugala", "Sapa-Sapa", "Sibutu", "Simunul", "Sitangkai", "South Ubian", "Tandubas", "Turtle Islands"],
  "Zambales": ["Botolan", "Cabangan", "Candelaria", "Castillejos", "Iba", "Masinloc", "Palauig", "San Antonio", "San Felipe", "San Marcelino", "San Narciso", "Santa Cruz", "Subic"],
  "Zamboanga del Norte": ["Baliguian", "Godod", "Gutalac", "Jose Dalman", "Kalawit", "Katipunan", "La Libertad", "Labason", "Leon B. Postigo", "Liloy", "Manukan", "Mutia", "Piñan", "Polanco", "President Manuel A. Roxas", "Rizal", "Salug", "Sergio Osmeña Sr.", "Siayan", "Sibuco", "Sibutad", "Sindangan", "Siocon", "Sirawai", "Tampilisan"],
   "Zamboanga del Sur": ["Aurora", "Bayog", "Dimataling", "Dinas", "Dumalinao", "Dumingag", "Guipos", "Josefina", "Kumalarang", "Labangan", "Lakewood", "Lapuyan", "Mahayag", "Margosatubig", "Midsalip", "Molave", "Pitogo", "Ramon Magsaysay", "San Miguel", "San Pablo", "Sominot", "Tabina", "Tambulig", "Tigbao", "Tukuran", "Vincenzo A. Sagun"],
  "Zamboanga Sibugay": ["Alicia", "Buug", "Diplahan", "Imelda", "Ipil", "Kabasalan", "Mabuhay", "Malangas", "Naga", "Olutanga", "Payao", "Roseller Lim", "Siay", "Talusan", "Titay", "Tungawan"]
};

// ✅ FULLY FUNCTIONAL SEARCHABLE DROPDOWN SYSTEM - NO BUGS
class SearchableDropdown {
  constructor(searchInputId, dropdownId, hiddenInputId, dataSource, options = {}) {
    this.searchInput = document.getElementById(searchInputId);
    this.dropdown = document.getElementById(dropdownId);
    this.hiddenInput = document.getElementById(hiddenInputId);
    this.dataSource = dataSource;
    this.isRegion = options.isRegion || false;
    this.dependentField = options.dependentField || null;
    
    this.init();
  }
  
  init() {
    this.searchInput.addEventListener('click', () => this.toggleDropdown());
    this.searchInput.addEventListener('focus', () => this.toggleDropdown());
    
    this.dropdown.addEventListener('click', (e) => {
      if (e.target.classList.contains('dropdown-item')) {
        this.selectItem(e.target);
      }
    });
    
    document.addEventListener('click', (e) => {
      if (!this.searchInput.contains(e.target) && !this.dropdown.contains(e.target)) {
        this.closeDropdown();
      }
    });
    
    // Load saved value
    this.loadSavedValue();
    
    // Handle dependent dropdowns
    if (this.dependentField) {
      this.searchInput.addEventListener('change', () => {
        this.updateDependentDropdown();
      });
    }
  }
  
  toggleDropdown() {
    if (this.dropdown.classList.contains('active')) {
      this.closeDropdown();
    } else {
      this.openDropdown();
    }
  }
  
  openDropdown() {
    this.dropdown.classList.add('active');
    this.populateDropdown();
    this.searchInput.style.borderColor = 'var(--c-brand-accent)';
  }
  
  closeDropdown() {
    this.dropdown.classList.remove('active');
    this.searchInput.style.borderColor = 'var(--c-slate-200)';
  }
  
  populateDropdown(query = '') {
    this.dropdown.innerHTML = '';
    
    let items = this.dataSource;
    
    if (query.trim()) {
      items = items.filter(item => 
        item.toLowerCase().includes(query.toLowerCase())
      );
    }
    
    if (items.length === 0) {
      this.dropdown.innerHTML = '<div class="dropdown-item" style="color: var(--c-text-sub);">No results found</div>';
      return;
    }
    
    items.slice(0, 10).forEach(item => { // Limit to 10 results
      const div = document.createElement('div');
      div.className = 'dropdown-item';
      div.textContent = item;
      div.dataset.value = item;
      this.dropdown.appendChild(div);
    });
  }
  
  selectItem(item) {
    const value = item.dataset.value;
    this.searchInput.value = value;
    this.hiddenInput.value = value;
    this.closeDropdown();
    
    // Trigger dependent dropdown update
    if (this.dependentField) {
      this.updateDependentDropdown();
    }
    
    // Visual feedback
    item.classList.add('selected');
    setTimeout(() => item.classList.remove('selected'), 200);
  }
  
  loadSavedValue() {
    const savedValue = this.hiddenInput.value;
    if (savedValue) {
      this.searchInput.value = savedValue;
    }
  }
  
  updateDependentDropdown() {
    // This will be called by region to update province dropdown
    const provinceDropdown = document.getElementById('provinceDropdown');
    if (provinceDropdown) {
      provinceDropdown.innerHTML = '';
    }
  }
}

// 🔗 INITIALIZE ALL DROPDOWNS AFTER DOM LOADED
document.addEventListener('DOMContentLoaded', function() {
  
  // 1. Region Dropdown
  const regionDropdown = new SearchableDropdown(
    'regionSearch',
    'regionDropdown',
    'regionInput',
    phRegions,
    { isRegion: true, dependentField: 'provinceSearch' }
  );
  
  // 2. Province Dropdown
  const provinceDropdown = new SearchableDropdown(
    'provinceSearch', 
    'provinceDropdown', 
    'provinceInput', 
    phProvinces
  );
  
  // 3. Municipality Dropdown (Province-dependent)
  const municipalityDropdown = new SearchableDropdown(
    'municipalitySearch',
    'municipalityDropdown',
    'municipalityInput',
    []
  );
  
  // 4. Province Selection Handler - POPULATE MUNICIPALITIES
  document.getElementById('provinceSearch').addEventListener('change', function() {
    const selectedProvince = document.getElementById('provinceInput').value;
    const municipalitySearch = document.getElementById('municipalitySearch');
    const municipalityDropdown = document.getElementById('municipalityDropdown');
    const municipalityInput = document.getElementById('municipalityInput');
    
    // Clear municipality
    municipalitySearch.value = '';
    municipalityInput.value = '';
    municipalityDropdown.innerHTML = '';
    
    if (selectedProvince && phMunicipalitiesByProvince[selectedProvince]) {
      municipalityDropdown.dataSource = phMunicipalitiesByProvince[selectedProvince];
    } else {
      municipalityDropdown.dataSource = [];
    }
  });
  
  // 5. Work Info Dropdowns (Free text with suggestions)
  const departments = ["DA - Bureau of Animal Industry", "DA - Regional Field Office", "DA - Veterinary Office", "LGU - Municipal Agriculture Office", "LGU - Provincial Veterinary Office", "Philippine Carabao Center", "Philippine Council for Agriculture", "Other"];
  
  const positions = ["Veterinarian", "Veterinary Technician", "Livestock Inspector", "Agriculture Officer", "Farm Inspector", "Quarantine Officer", "Senior Agriculturist", "Chief Veterinary Officer", "Other"];
  
  new SearchableDropdown('govIdSearch', 'govIdDropdown', 'gov_id', []); // Free text
  new SearchableDropdown('departmentSearch', 'departmentDropdown', 'department', departments);
  new SearchableDropdown('positionSearch', 'positionDropdown', 'position', positions);
  new SearchableDropdown('officeSearch', 'officeDropdown', 'office', []); // Free text
  
  // 6. Profile Picture Preview (Already working)
  document.getElementById('profilePicInput').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const preview = document.getElementById('editProfilePreview');
    
    if (file) {
      const reader = new FileReader();
      reader.onload = function(e) {
        preview.src = e.target.result;
        preview.style.display = 'block';
      };
      reader.readAsDataURL(file);
    }
  });
  
  // 7. SEARCH ON TYPE (Live search)
  document.querySelectorAll('.searchable-dropdown input').forEach(input => {
    input.addEventListener('input', function(e) {
      const dropdown = this.parentElement.querySelector('.dropdown-list');
      const dropdownInstance = window.dropdownInstances[this.id]; // Store instances globally if needed
      
      if (dropdown.classList.contains('active')) {
        // Trigger search in open dropdown
        const event = new Event('search');
        dropdown.dispatchEvent(event);
      }
    });
  });
  
  console.log('✅ All searchable dropdowns initialized successfully!');
});

// 🌟 GLOBAL FUNCTIONS FOR PROFILE MODAL
function openEditProfile() {
  document.getElementById('editProfileModal').classList.add('active');
  document.body.style.overflow = 'hidden';
  
  // Pre-populate all fields
  const userProfile = <?php echo json_encode($user_profile); ?>;
  Object.keys(userProfile).forEach(key => {
    const input = document.querySelector(`[name="${key}"]`);
    if (input && userProfile[key]) {
      input.value = userProfile[key];
    }
  });
}

function closeEditProfile() {
  document.getElementById('editProfileModal').classList.remove('active');
  document.body.style.overflow = '';
}

// 🎯 FORM SUBMISSION - READY TO WORK
document.getElementById('editProfileForm').addEventListener('submit', function(e) {
  e.preventDefault();
  
  const formData = new FormData(this);
  formData.append('user_id', <?php echo $user_id; ?>);
  formData.append('action', 'update_profile');
  
  showLoading(true);
  
  fetch(window.location.href, {  // Same page handles the POST
    method: 'POST',
    body: formData
  })
  .then(response => response.json())
  .then(data => {
    if (data.success) {
      showToast('✅ Profile updated successfully!', 'success');
      closeEditProfile();
      setTimeout(() => location.reload(), 1500);
    } else {
      showToast('❌ ' + (data.message || 'Update failed'), 'error');
    }
  })
  .catch(error => {
    showToast('❌ Network error. Please try again.', 'error');
    console.error('Profile update error:', error);
  })
  .finally(() => showLoading(false));
});

// 🔧 UTILITY FUNCTIONS
function showToast(message, type = 'info') {
  const toast = document.createElement('div');
  toast.style.cssText = `
    position: fixed; top: 90px; right: 20px; 
    background: ${type === 'success' ? '#10B981' : type === 'error' ? '#EF4444' : '#3B82F6'}; 
    color: white; padding: 16px 24px; 
    border-radius: 12px; font-weight: 600; 
    z-index: 9999; transform: translateX(400px); 
    transition: transform 0.3s ease;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    font-family: 'DM Sans', sans-serif;
  `;
  toast.textContent = message;
  document.body.appendChild(toast);
  
  setTimeout(() => toast.style.transform = 'translateX(0)', 100);
  setTimeout(() => {
    toast.style.transform = 'translateX(400px)';
    setTimeout(() => document.body.removeChild(toast), 300);
  }, 4000);
}

function showLoading(show = true) {
  const overlay = document.getElementById('loadingOverlay');
  overlay.style.display = show ? 'flex' : 'none';
}

// Open Modal
function openEditProfile() {
    document.getElementById('editProfileModal').style.display = 'flex';
}

// Close Modal
function closeEditProfile() {
    document.getElementById('editProfileModal').style.display = 'none';
}

// Image Preview Logic
document.getElementById('profilePicInput').addEventListener('change', function(e) {
    const reader = new FileReader();
    reader.onload = function(e) {
        const preview = document.getElementById('editProfilePreview');
        preview.src = e.target.result;
        preview.style.display = 'block';
    }
    reader.readAsDataURL(this.files[0]);
});

// Form Submission via AJAX
document.getElementById('editProfileForm').onsubmit = async function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    try {
        const response = await fetch('update_profile.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            alert('Profile updated successfully!');
            location.reload(); // Refresh to show changes
        } else {
            alert('Error: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
    }
};
  </script>