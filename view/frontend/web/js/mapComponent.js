function mapComponent(_hyvaData) {
	// Robust hyvaData initialization (from newer versions)
	const hyvaData =
		_hyvaData && typeof _hyvaData === "object" && !Array.isArray(_hyvaData)
			? _hyvaData
			: {};

	// Safely initialize properties that depend on hyvaData
	const isLoggedIn =
		typeof hyvaData.isLoggedIn === "boolean" ? hyvaData.isLoggedIn : false;
	const savedAddressesList = Array.isArray(hyvaData.savedAddresses)
		? hyvaData.savedAddresses
		: [];
	const initialCustomerSessionData =
		hyvaData.customerData && typeof hyvaData.customerData === "object"
			? hyvaData.customerData
			: {};
	const sourcesDataList = Array.isArray(hyvaData.sourcesData)
		? hyvaData.sourcesData
		: [];

	// Translatable labels (defaults, should be passed via hyvaData from PHP Block)
	const labelNationwideShipping =
		hyvaData.labelNationwideShipping || "Nationwide Shipping";
	const labelNoLocalBranch =
		hyvaData.labelNoLocalBranchAlert ||
		"No local delivery branches serve this area. Nationwide shipping may be available for eligible items.";
	const labelUnableToFindBranchForAddress =
		hyvaData.labelUnableToFindBranchForAddressAlert ||
		"Unable to find a branch within delivery range for the selected address.";
	const labelSelectValidAddress =
		hyvaData.labelSelectValidAddress ||
		"Please select a valid address from the dropdown.";
	const labelEnterValidAddress =
		hyvaData.labelEnterValidAddress ||
		"Please enter and select a valid address.";

	return {
		// Properties from newer versions + old version defaults
		isModalOpen: false,
		isEditingAddress: false,
		showSavedAddresses: isLoggedIn && savedAddressesList.length > 0,
		selectedBranchName: null,
		selectedBranchPhone: null,
		selectedSourceCode: null,
		currentAddress: "",
		isFetchingDeliveryBranch: false,
		// Initialize latitude/longitude carefully
		latitude: parseFloat(hyvaData.defaultLatitude) || 24.7136, // Riyadh default
		longitude: parseFloat(hyvaData.defaultLongitude) || 46.6753, // Riyadh default
		googleMapsApiLoaded: false,
		map: null,
		marker: null,
		autocomplete: null,
		savedAddresses: savedAddressesList,
		selectedAddress: null,
		isProcessing: false,
		isAddressValid: false,
		lastValidCoordinates: null,
		street: "",
		city: "",
		region: "",
		postcode: "",
		country: "",

		_apiKey: hyvaData.apiKey || null, // Crucial for map
		_isLoggedIn: isLoggedIn,
		_customerSessionData: initialCustomerSessionData,
		_sourcesData: sourcesDataList,

		// --- Initialization (combining old and new) ---
		init() {
			if (!this._apiKey) {
				console.error(
					"[MapC_Init] Google Maps API key is missing. Map functionality will be disabled."
				);
			}
			// Initialize lastValidCoordinates from current latitude/longitude
			if (!isNaN(this.latitude) && !isNaN(this.longitude)) {
				this.lastValidCoordinates = { lat: this.latitude, lng: this.longitude };
			}

			Alpine.store("mapComponentInstance", this); // From newer versions
			this.fetchDeliveryBranchData(); // Fetch initial branch data (common to both)

			// Watch for saved addresses to be loaded
			if (this._isLoggedIn && !this.selectedSourceCode) {
				this.$watch("savedAddresses", (newAddresses) => {
					if (
						newAddresses.length > 0 &&
						!this.selectedSourceCode &&
						!this._hasAutoSelected
					) {
						this._hasAutoSelected = true;
						this.checkAndAutoSelectDefaultAddress();
					}
				});
			}

			// Event listener setup (common pattern)
			if (!window.mapCompPrivateContentLoaded) {
				// Use a unique flag
				window.addEventListener("private-content-loaded", () => {
					this.fetchDeliveryBranchData();
				});
				window.mapCompPrivateContentLoaded = true;
			}
			// Auto-select default shipping address if conditions are met
			if (hyvaData.shouldAutoSelectAddress && hyvaData.defaultShippingAddress) {
				this.autoSelectDefaultAddress();
			}
		},

		// --- Data Fetching & Updating (primarily from newer, refined versions) ---
		async fetchDeliveryBranchData() {
			// Check the new specific flag for this function
			if (this.isFetchingDeliveryBranch) {
				return;
			}
			this.isFetchingDeliveryBranch = true; // Set lock for this function

			// The old problematic condition:
			// if (this.isProcessing && this.isModalOpen) {
			//     this.isFetchingDeliveryBranch = false; // Make sure to reset if returning early
			//     return;
			// }
			// This old condition is removed because the component's general 'isProcessing' state
			// should not block this critical internal refresh step.

			const timestamp = Date.now();
			const url = `/customer/section/load/?sections=delivery-branch&force_new_section_timestamp=true&_=${timestamp}`;
			try {
				const response = await fetch(url, {
					method: "GET",
					credentials: "same-origin",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
				});
				if (!response.ok)
					throw new Error(`HTTP error! status: ${response.status}`);

				const sectionData = await response.json();
				const deliveryBranchData = sectionData["delivery-branch"] || {};
				const customerDataStore = Alpine.store("customerData");

				if (customerDataStore) {
					if (!customerDataStore.data) customerDataStore.data = {};
					customerDataStore.data = {
						...customerDataStore.data,
						...sectionData, // Spread sectionData first
					};
					// Ensure 'delivery-branch' is properly updated, creating a new object reference
					// to help Alpine's reactivity.
					customerDataStore.data["delivery-branch"] = {
						...(deliveryBranchData || {}),
					};
				}

				// Update component's local state directly
				this.selectedBranchName =
					deliveryBranchData.selected_branch_name || null;
				this.selectedBranchPhone =
					deliveryBranchData.selected_branch_phone || null;
				this.selectedSourceCode = // <--- This needs to be from the FRESHLY fetched data
					deliveryBranchData.selected_source_code || null;
				const newLat = parseFloat(deliveryBranchData.customer_latitude);
				const newLng = parseFloat(deliveryBranchData.customer_longitude);
				if (
					!isNaN(newLat) &&
					!isNaN(newLng) &&
					(newLat !== 0 || newLng !== 0)
				) {
					this.latitude = newLat;
					this.longitude = newLng;
					this.lastValidCoordinates = {
						lat: this.latitude,
						lng: this.longitude,
					};
				}
				if (
					!this.selectedSourceCode &&
					this._isLoggedIn &&
					!this._hasAutoSelected
				) {
					this._hasAutoSelected = true; // Prevent multiple auto-selections
					this.checkAndAutoSelectDefaultAddress();
				}
			} catch (error) {
				console.error(
					"[MapC_Fetch] Error fetching/processing delivery branch data:",
					error
				);
			} finally {
				this.isFetchingDeliveryBranch = false; // Release lock for this function
			}
		},

		checkAndAutoSelectDefaultAddress() {
			// Only auto-select if we have saved addresses and no source is selected
			if (this.savedAddresses.length > 0 && !this.selectedSourceCode) {
				// Find default shipping address
				const defaultAddress = this.savedAddresses.find(
					(addr) => addr.is_default_shipping
				);

				if (
					defaultAddress &&
					defaultAddress.latitude &&
					defaultAddress.longitude
				) {
					console.log(
						"[MapC_AutoSelect] Auto-selecting default shipping address"
					);

					// Don't open modal, just process the selection
					this.isProcessing = true;

					// Update coordinates
					this.updateCoordinates(
						parseFloat(defaultAddress.latitude),
						parseFloat(defaultAddress.longitude)
					);

					// Find nearest branch
					const nearestBranch = this.findNearestBranch(
						this.latitude,
						this.longitude,
						this._sourcesData
					);

					if (nearestBranch) {
						this.selectedBranchName = nearestBranch.source_name;
						this.selectedBranchPhone = nearestBranch.phone;
						this.selectedSourceCode = nearestBranch.source_code;

						// Update backend
						this.updateDeliveryBranchDataOnBackend()
							.then(() => {
								console.log(
									"[MapC_AutoSelect] Default address auto-selected successfully"
								);
							})
							.catch((error) => {
								console.error("[MapC_AutoSelect] Error:", error);
							})
							.finally(() => {
								this.isProcessing = false;
							});
					} else {
						this.isProcessing = false;
						console.log(
							"[MapC_AutoSelect] No branch within range of default address"
						);
					}
				}
			}
		},

		async updateDeliveryBranchDataOnBackend() {
			this.isProcessing = true;
			try {
				const response = await fetch("/stockavailability/branch/update", {
					method: "POST",
					headers: {
						"Content-Type": "application/json",
						"X-Requested-With": "XMLHttpRequest",
					},
					body: JSON.stringify({
						selected_source_code: this.selectedSourceCode,
						selected_branch_name: this.selectedBranchName,
						selected_branch_phone: this.selectedBranchPhone,
						customer_latitude: this.latitude,
						customer_longitude: this.longitude,
					}),
					credentials: "same-origin",
				});
				const data = await response.json();
				if (data.success) {
					await this.fetchDeliveryBranchData(); // This reloads store, which DS watches
				} else {
					throw new Error(
						data.message || "Failed to update delivery branch data on backend"
					);
				}
			} catch (error) {
				console.error("[MapC_UpdateBackend] Error:", error);
				alert("An error occurred updating your location. Please try again.");
				throw error; // Re-throw so callers like confirmLocation can know
			} finally {
				this.isProcessing = false;
			}
		},

		// --- Modal and UI Toggles (closer to older version's directness + newer flags) ---
		toggleModal() {
			this.isModalOpen = !this.isModalOpen;
			if (this.isModalOpen) {
				if (!this._apiKey) {
					console.warn("[MapC_Modal] Cannot init map, API key missing.");
					return;
				}
				if (!this.googleMapsApiLoaded) {
					this.loadGoogleMapsApi();
				} else {
					// Use setTimeout to ensure DOM is ready if modal has transitions
					setTimeout(() => this.initMap(), 50);
				}
			}
		},

		// --- Google Maps API and Functionality (prioritizing OLDER WORKING PATTERNS) ---
		loadGoogleMapsApi() {
			// Using the global callback pattern from your older working version
			window.componentInitMap = this.initMap.bind(this); // Bind `this` context

			if (document.querySelector('script[src*="maps.googleapis.com"]')) {
				this.googleMapsApiLoaded = true; // Assume loaded
				if (typeof google !== "undefined" && google.maps) {
					window.componentInitMap(); // Call it directly
				} else {
					console.warn(
						"[MapC_LoadAPI] Script tag present, but google.maps not ready. Waiting for callback or manual init."
					);
					// The callback in the script URL will eventually call componentInitMap
				}
				return;
			}

			const script = document.createElement("script");
			// Using this._apiKey from component state
			script.src = `https://maps.googleapis.com/maps/api/js?key=${this._apiKey}&libraries=places&callback=componentInitMap`;
			script.async = true;
			script.defer = true;
			// script.onload from older version was just setting a flag, the callback handles init.
			// The callback `componentInitMap` will call initMap, which sets googleMapsApiLoaded.
			script.onerror = () =>
				console.error("[MapC_LoadAPI] Failed to load Google Maps API script.");
			document.head.appendChild(script);
		},

		initMap() {
			if (typeof google === "undefined" || typeof google.maps === "undefined") {
				console.error(
					"[MapC_InitMap] Google Maps API (google.maps) not available."
				);
				return;
			}
			// Use x-ref if available, otherwise fallback to ID (from newer version, good robustness)
			const mapContainer =
				this.$refs.mapContainer || document.getElementById("amCountrySelector");

			if (!mapContainer) {
				console.error(
					"[MapC_InitMap] Map container ('mapContainer' x-ref or 'amCountrySelector' ID) not found."
				);
				return;
			}
			if (isNaN(this.latitude) || isNaN(this.longitude)) {
				console.error(
					`[MapC_InitMap] Invalid coordinates: Lat=${this.latitude}, Lng=${this.longitude}. Using defaults.`
				);
				// Fallback to ensure map loads even if coordinates are bad.
				this.latitude = 24.7136;
				this.longitude = 46.6753;
			}

			const center = { lat: this.latitude, lng: this.longitude };
			const mapOptions = {
				center: center,
				zoom: 12, // Zoom from newer version, 8 was in old
				mapTypeControl: false,
				streetViewControl: false,
				fullscreenControl: false, // Options from newer
			};

			try {
				this.map = new google.maps.Map(mapContainer, mapOptions);
				this.googleMapsApiLoaded = true; // Set flag here upon successful map creation

				this.createMarker(center); // createMarker from newer version
				this.initAutocomplete(); // initAutocomplete from newer version

				// Geocode only if address isn't set and coords are valid (from newer version)
				// Check this.currentAddress against the value derived from lat/lng
				// If currentAddress is empty OR geocoding gives a different one, then update it.
				if (
					!this.currentAddress &&
					(this.latitude !== 0 || this.longitude !== 0)
				) {
					this.reverseGeocodeAddress();
				}
			} catch (e) {
				console.error("[MapC_InitMap] Error initializing Google Map parts:", e);
			}
		},

		// Autocomplete from NEWER version (more robust)
		initAutocomplete() {
			if (
				!this.map ||
				!google.maps.places ||
				!google.maps.places.Autocomplete
			) {
				console.error("[MapC_AutoComplete] Prerequisites not met.");
				return;
			}
			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			if (!input) {
				console.error("[MapC_AutoComplete] Address search input not found.");
				return;
			}

			this.autocomplete = new google.maps.places.Autocomplete(input, {
				componentRestrictions: { country: "sa" },
				fields: ["address_components", "formatted_address", "geometry", "name"],
			});
			this.autocomplete.bindTo("bounds", this.map);
			this.autocomplete.addListener("place_changed", () => {
				const place = this.autocomplete.getPlace();
				if (!place.geometry || !place.geometry.location) {
					this.showAddressError(labelSelectValidAddress); // Use label
					this.isAddressValid = false;
					return;
				}
				const location = place.geometry.location;
				this.updateCoordinates(location.lat(), location.lng()); // Central update
				this.map.setCenter(location);
				if (this.marker) this.marker.setPosition(location);
				else this.createMarker(location);
				this.currentAddress = place.formatted_address;
				input.value = this.currentAddress;
				this.clearAddressError();
				this.parseAddressComponents(place.address_components);
				this.isAddressValid = true;
			});
			// Blur/Enter listeners from newer version
			input.addEventListener("blur", () =>
				this.validateManualInput(input.value)
			);
			input.addEventListener("keydown", (e) => {
				if (e.key === "Enter") {
					e.preventDefault();
					this.validateManualInput(input.value);
				}
			});
		},

		// Geocoding from NEWER version (updates component state)
		reverseGeocodeAddress() {
			if (!this.map || !google.maps.Geocoder) {
				console.error("[MapC_ReverseGeo] Geocoder missing.");
				return;
			}
			const geocoder = new google.maps.Geocoder();
			const latlng = { lat: this.latitude, lng: this.longitude };
			geocoder.geocode({ location: latlng }, (results, status) => {
				if (status === "OK" && results && results[0]) {
					this.currentAddress = results[0].formatted_address;
					const input =
						this.$refs.addressSearch ||
						document.getElementById("am-address-search");
					if (input) input.value = this.currentAddress;
					this.parseAddressComponents(results[0].address_components);
					this.isAddressValid = true; // Address from geocoding is valid
					this.clearAddressError();
				} else {
					console.error("[MapC_ReverseGeo] Geocode failed:", status);
					this.isAddressValid = false; // Geocoding failed
				}
			});
		},

		// --- Location Confirmation & Branch Logic (from NEWER, refined versions) ---
		async confirmLocation() {
			if (this.isProcessing) {
				return;
			}

			const input =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			this.validateManualInput(
				input ? input.value.trim() : this.currentAddress
			);
			if (!this.isAddressValid) {
				if (
					!document.getElementById("address-error-message") &&
					this.currentAddress
				) {
					// Only show error if address was attempted
					this.showAddressError(labelEnterValidAddress); // Use label
				}
				return;
			}
			this.clearAddressError();
			this.isProcessing = true;

			try {
				const nearestBranch = this.findNearestBranch(
					this.latitude,
					this.longitude,
					this._sourcesData
				);
				if (!nearestBranch) {
					alert(labelNoLocalBranch); // Using the pre-defined label
					this.selectedSourceCode = "NATIONWIDE_SHIPPING";
					this.selectedBranchName = labelNationwideShipping; // Using the pre-defined label
					this.selectedBranchPhone = null;
				} else {
					this.selectedBranchName = nearestBranch.source_name;
					this.selectedBranchPhone = nearestBranch.phone;
					this.selectedSourceCode = nearestBranch.source_code;
				}

				if (this._isLoggedIn) {
					try {
						await this.saveAddressToServer();
					} catch (e) {
						console.warn(
							"[MapC_Confirm] Error saving address (non-critical):",
							e
						);
					}
				}
				await this.updateDeliveryBranchDataOnBackend(); // This will POST, then GET, then update store
				this.isModalOpen = false;
			} catch (error) {
				// Error is usually alerted in updateDeliveryBranchDataOnBackend if it's from there
				console.error("[MapC_Confirm] Error in confirm process:", error);
			} finally {
				this.isProcessing = false;
			}
		},

		selectAddress(address) {
			if (
				!address ||
				typeof address.latitude === "undefined" ||
				typeof address.longitude === "undefined"
			) {
				alert("Selected address has invalid coordinates.");
				return;
			}
			this.isProcessing = true;

			this.selectedAddress = address;
			this.updateCoordinates(
				parseFloat(address.latitude),
				parseFloat(address.longitude)
			);
			this.currentAddress =
				address.details || `${address.street}, ${address.city}`;
			this.street = address.street || "";
			this.city = address.city || "";
			this.region = address.region || "";
			this.postcode = address.postcode || "";
			this.country = address.country_id || "SA";
			this.isAddressValid = true;
			this.clearAddressError();

			const nearestBranch = this.findNearestBranch(
				this.latitude,
				this.longitude,
				this._sourcesData
			);
			if (!nearestBranch) {
				alert(labelUnableToFindBranchForAddress); // Use pre-defined label
				this.selectedSourceCode = "NATIONWIDE_SHIPPING";
				this.selectedBranchName = labelNationwideShipping; // Use pre-defined label
				this.selectedBranchPhone = null;
			} else {
				this.selectedBranchName = nearestBranch.source_name;
				this.selectedBranchPhone = nearestBranch.phone;
				this.selectedSourceCode = nearestBranch.source_code;
			}

			this.updateDeliveryBranchDataOnBackend()
				.then(() => {
					this.isModalOpen = false;
				})
				.catch((error) =>
					console.error(
						"[MapC_SelectAddr] Error post-selecting address:",
						error
					)
				)
				.finally(() => {
					this.isProcessing = false;
				});
		},

		// Add new method:
		autoSelectDefaultAddress() {
			const defaultAddr = this._hyvaData.defaultShippingAddress;
			if (!defaultAddr || !defaultAddr.latitude || !defaultAddr.longitude)
				return;

			// Find the address in saved addresses
			const savedAddr = this.savedAddresses.find(
				(addr) => addr.id === defaultAddr.address_id
			);

			if (savedAddr) {
				// Auto-select without opening modal
				this.selectAddress(savedAddr);
			} else {
				// If not found in saved list, still update coordinates
				this.updateCoordinates(
					parseFloat(defaultAddr.latitude),
					parseFloat(defaultAddr.longitude)
				);

				// Find nearest branch
				const nearestBranch = this.findNearestBranch(
					this.latitude,
					this.longitude,
					this._sourcesData
				);

				if (nearestBranch) {
					this.selectedBranchName = nearestBranch.source_name;
					this.selectedBranchPhone = nearestBranch.phone;
					this.selectedSourceCode = nearestBranch.source_code;

					// Update backend silently
					this.updateDeliveryBranchDataOnBackend().catch((error) => {
						console.error("[MapC_AutoSelect] Error:", error);
					});
				}
			}
		},

		// --- Helper methods (merged from old and new) ---
		findNearestBranch(lat, lng, sourcesData) {
			if (!sourcesData || sourcesData.length === 0) return null;
			let nearestBranch = null;
			let shortestDistance = Infinity;
			sourcesData.forEach((source) => {
				if (!source.latitude || !source.longitude || !source.delivery_range_km)
					return;
				const distance = this.calculateDistance(
					lat,
					lng,
					parseFloat(source.latitude),
					parseFloat(source.longitude)
				);
				const deliveryRange = parseFloat(source.delivery_range_km);
				if (distance <= deliveryRange && distance < shortestDistance) {
					shortestDistance = distance;
					nearestBranch = source;
				}
			});
			return nearestBranch;
		},
		calculateDistance(lat1, lng1, lat2, lng2) {
			const R = 6371;
			const φ1 = (lat1 * Math.PI) / 180,
				φ2 = (lat2 * Math.PI) / 180,
				Δφ = ((lat2 - lat1) * Math.PI) / 180,
				Δλ = ((lng2 - lng1) * Math.PI) / 180;
			const a =
				Math.sin(Δφ / 2) ** 2 +
				Math.cos(φ1) * Math.cos(φ2) * Math.sin(Δλ / 2) ** 2;
			const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
			return R * c;
		},
		degreesToRadians(degrees) {
			return degrees * (Math.PI / 180);
		},
		updateCoordinates(lat, lng) {
			this.latitude = lat;
			this.longitude = lng;
			this.lastValidCoordinates = { lat, lng };
			this.isAddressValid = true;
		}, // From newer
		parseAddressComponents(components) {
			/* ... from newer version, already included above ... */ const map = {
				street_number: "",
				route: "",
				sublocality: "",
				locality: "",
				administrative_area_level_1: "",
				postal_code: "",
				country: "",
			};
			components.forEach((c) => {
				const t = c.types[0];
				if (map.hasOwnProperty(t)) map[t] = c.long_name;
				if (t === "country") map.country = c.short_name;
			});
			this.street = [map.street_number, map.route, map.sublocality]
				.filter(Boolean)
				.join(", ");
			this.city = map.locality;
			this.region = map.administrative_area_level_1;
			this.postcode = map.postal_code;
			this.country = map.country || "SA";
		},
		validateManualInput(val) {
			/* ... from newer, already included above ... */ const v = (
				val || ""
			).trim();
			if (v === "" && this.currentAddress === "") {
				this.clearAddressError();
				this.isAddressValid = false;
				return;
			}
			if (v !== this.currentAddress) {
				this.showAddressError(
					hyvaData.labelSelectValidAddress || "Select from suggestions."
				);
				this.isAddressValid = false;
			} else if (v === this.currentAddress && this.currentAddress !== "") {
				this.clearAddressError();
				this.isAddressValid = true;
			}
		},
		showAddressError(msg) {
			/* ... from newer, already included above ... */ const i =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			this.clearAddressError();
			if (i) {
				i.classList.add("border-red-500", "bg-red-50");
				i.classList.remove("focus:ring-primary", "focus:border-primary");
			}
			const d = document.createElement("div");
			d.className = "text-red-600 text-xs mt-1";
			d.textContent = msg;
			d.id = "address-error-message";
			i?.parentNode?.insertBefore(d, i.nextSibling);
		},
		clearAddressError() {
			/* ... from newer, already included above ... */ const i =
				this.$refs.addressSearch ||
				document.getElementById("am-address-search");
			const d = document.getElementById("address-error-message");
			if (i) {
				i.classList.remove("border-red-500", "bg-red-50");
				i.classList.add("focus:ring-primary", "focus:border-primary");
			}
			d?.remove();
		},
		saveAddressToServer() {
			/* ... from newer, already included above ... */ if (
				!this.street ||
				!this.city ||
				!this.country
			)
				throw new Error("Addr incomplete");
			const ad = {
				address_id: this.selectedAddress?.id || null,
				firstname: this._customerSessionData.firstname || "Cust",
				lastname: this._customerSessionData.lastname || "User",
				telephone: this._customerSessionData.telephone || "000",
				street: [this.street],
				city: this.city,
				postcode: this.postcode || "00000",
				country_id: this.country,
				region: this.region || "",
				latitude: this.latitude,
				longitude: this.longitude,
				is_default_shipping:
					this.savedAddresses.length === 0 ||
					(this.selectedAddress
						? this.selectedAddress.is_default_shipping
						: true),
				is_default_billing:
					this.savedAddresses.length === 0 ||
					(this.selectedAddress
						? this.selectedAddress.is_default_billing
						: false),
			};
			return fetch("/stockavailability/address/save", {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					"X-Requested-With": "XMLHttpRequest",
				},
				body: JSON.stringify(ad),
				credentials: "same-origin",
			})
				.then((r) => r.json())
				.then((d) => {
					if (!d.success)
						throw new Error(d.message || "Failed to save address");
					return d;
				});
		},
		createMarker(position) {
			/* ... from newer, already included above ... */ if (
				!this.map ||
				typeof google === "undefined" ||
				!google.maps.Marker
			)
				return;
			if (this.marker) this.marker.setMap(null);
			this.marker = new google.maps.Marker({
				position,
				map: this.map,
				draggable: true,
				title: "Delivery Location",
			});
			this.marker.addListener("dragend", (e) => {
				this.updateCoordinates(e.latLng.lat(), e.latLng.lng());
				this.reverseGeocodeAddress();
			});
		},
		useCurrentLocation() {
			/* ... from newer, already included above ... */ if (
				!navigator.geolocation
			) {
				alert("Geo not supported.");
				return;
			}
			this.isProcessing = true;
			navigator.geolocation.getCurrentPosition(
				(p) => {
					this.updateCoordinates(p.coords.latitude, p.coords.longitude);
					if (this.map) {
						const nc = { lat: this.latitude, lng: this.longitude };
						this.map.setCenter(nc);
						if (this.marker) this.marker.setPosition(nc);
						else this.createMarker(nc);
						this.reverseGeocodeAddress();
					}
					this.isProcessing = false;
				},
				(e) => {
					console.error(e);
					this.isProcessing = false;
				},
				{ enableHighAccuracy: true }
			);
		},
		toggleAddressView() {
			/* ... from newer, already included above ... */ this.showSavedAddresses =
				!this.showSavedAddresses;
			this.isEditingAddress = false;
			this.clearAddressError();
			if (!this.showSavedAddresses && this.googleMapsApiLoaded)
				setTimeout(() => this.initMap(), 100);
		},
		editAddress(address) {
			/* ... from newer, already included above ... */ if (
				address &&
				typeof address.latitude !== "undefined"
			) {
				this.selectedAddress = address;
				this.updateCoordinates(
					parseFloat(address.latitude),
					parseFloat(address.longitude)
				);
				this.currentAddress =
					address.details || `${address.street}, ${address.city}`;
				this.street = address.street || "";
				this.city = address.city || "";
				this.region = address.region || "";
				this.postcode = address.postcode || "";
				this.country = address.country_id || "SA";
				this.isEditingAddress = true;
				this.showSavedAddresses = false;
				this.isAddressValid = true;
				if (!this.googleMapsApiLoaded && this._apiKey) this.loadGoogleMapsApi();
				else if (this.googleMapsApiLoaded)
					setTimeout(() => {
						if (!this.map) this.initMap();
						else {
							const p = { lat: this.latitude, lng: this.longitude };
							this.map.setCenter(p);
							if (this.marker) this.marker.setPosition(p);
							else this.createMarker(p);
						}
						const i =
							this.$refs.addressSearch ||
							document.getElementById("am-address-search");
						if (i) i.value = this.currentAddress;
					}, 150);
			} else {
				alert("Addr invalid for edit.");
			}
		},
	};
}

document.addEventListener("alpine:init", () => {
	if (!Alpine.store("customerData")) {
		Alpine.store("customerData", {
			data: {},
			// Removed explicit get/set from older version as Alpine handles reactivity on .data directly
		});
	}
	Alpine.data("mapComponent", mapComponent);
	window.toggleDeliveryLocationModal = () => {
		// Global helper from newer versions
		const instance = Alpine.store("mapComponentInstance");
		if (instance) {
			instance.toggleModal();
		} else {
			console.error("mapComponent instance not found for global toggle.");
		}
	};
});
