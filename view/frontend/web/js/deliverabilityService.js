(function () {
	let deliverableMap = {}; // { sku: "Yes" | "No" }
	let watchedSkus = new Set();
	let watcherInitialized = false;
	let previousSourceCode = null; // Tracks the last *processed* source code
	let effectDebounceTimeout = null; // Timer for the Alpine.effect
	let fetchInProgress = false;
	let requestQueue = new Map(); // To prevent duplicate fetches for the exact same request

	const DEBOUNCE_DELAY = 300; // Debounce for the effect watcher
	const RETRY_ATTEMPTS = 3;
	const RETRY_DELAY = 1000;

	function initService() {
		// Renamed from initWatcher for clarity
		if (watcherInitialized) return;
		watcherInitialized = true;

		document.addEventListener("alpine:init", () => {
			initializeDeliverabilityStore();
			setupSourceCodeEffectWatcher(); // The core reactive mechanism
		});
	}

	function initializeDeliverabilityStore() {
		if (!Alpine.store("deliverability")) {
			Alpine.store("deliverability", {
				map: {},
				selected_source_code: "", // This will be updated by the effect watcher
				isLoading: false,
				error: null,
				hideUndeliverable:
					localStorage.getItem("hideUndeliverable") === "true" || false,
				isDeliverable(sku) {
					return this.map[sku] === "Yes";
				},
				getStatus(sku) {
					return this.map[sku] || "Yes";
				}, // Default to Yes if not found
				hasData(sku) {
					return this.map.hasOwnProperty(sku);
				},
				setError(error) {
					this.error = error;
					console.error("[DService] Error:", error);
				},
				clearError() {
					this.error = null;
				},
				savePreference() {
					localStorage.setItem("hideUndeliverable", this.hideUndeliverable);
					// Trigger visibility update
					this.updateProductVisibility();
				},
				updateProductVisibility() {
					// Dispatch custom event that product listings can listen to
					window.dispatchEvent(
						new CustomEvent("deliverability-filter-changed", {
							detail: { hideUndeliverable: this.hideUndeliverable },
						})
					);
				},
			});
		}
		// Initial check on page load based on what customerData might already have
		const initialSource =
			Alpine.store("customerData")?.data?.["delivery-branch"]
				?.selected_source_code || "";

		processSourceCodeChange(initialSource); // Process initial state
	}

	function setupSourceCodeEffectWatcher() {
		Alpine.effect(() => {
			const currentGlobalSourceCode =
				Alpine.store("customerData")?.data?.["delivery-branch"]
					?.selected_source_code || "";

			if (effectDebounceTimeout) clearTimeout(effectDebounceTimeout);

			effectDebounceTimeout = setTimeout(() => {
				processSourceCodeChange(currentGlobalSourceCode);
			}, DEBOUNCE_DELAY);
		});
	}

	// Central logic to handle a source code change
	function processSourceCodeChange(currentSourceCode) {
		const store = Alpine.store("deliverability");

		if (!store) {
			console.error("[DService_PROCESS] Deliverability store not initialized!");
			return;
		}

		// Update the deliverability store's own record of the source code
		store.selected_source_code = currentSourceCode;

		if (currentSourceCode === previousSourceCode) {
			return;
		}

		const oldPreviousForLog = previousSourceCode;
		previousSourceCode = currentSourceCode; // Update the module-level processed source
		store.clearError();

		if (!currentSourceCode || !currentSourceCode.trim()) {
			setAllWatchedSkusToDefault();
			return;
		}

		deliverableMap = {}; // Reset internal cache
		store.map = {}; // Reset store's map

		if (watchedSkus.size > 0) {
			fetchData(currentSourceCode, Array.from(watchedSkus));
		} else {
		}
	}

	function setAllWatchedSkusToDefault() {
		const newMap = {};
		watchedSkus.forEach((sku) => {
			newMap[sku] = "Yes"; // Default to deliverable
		});
		deliverableMap = { ...newMap }; // Update internal map
		const store = Alpine.store("deliverability");
		if (store) {
			store.map = { ...deliverableMap };
		}
	}

	async function fetchData(sourceCode, skus, retryCount = 0) {
		// Safeguards (though processSourceCodeChange should prevent empty source/skus)
		if (!Array.isArray(skus) || skus.length === 0) return;
		if (!sourceCode || !sourceCode.trim()) {
			setAllWatchedSkusToDefault();
			return;
		}

		const requestKey = `${sourceCode}_${skus.sort().join(",")}`;
		if (requestQueue.has(requestKey)) {
			return requestQueue.get(requestKey);
		}
		if (fetchInProgress) {
			setTimeout(
				() => fetchData(sourceCode, skus, retryCount),
				100 + Math.random() * 50
			);
			return;
		}

		fetchInProgress = true;
		const store = Alpine.store("deliverability");
		store.isLoading = true;

		const fetchPromise = performFetchLogic(sourceCode, skus, retryCount);
		requestQueue.set(requestKey, fetchPromise);

		try {
			await fetchPromise;
		} finally {
			fetchInProgress = false;
			store.isLoading = false;
			requestQueue.delete(requestKey);
		}
	}

	async function performFetchLogic(sourceCode, skus, retryCount) {
		if (!window.BASE_URL) {
			Alpine.store("deliverability")?.setError("BASE_URL not defined");
			throw new Error("BASE_URL not defined");
		}
		const url = buildFetchUrl(sourceCode, skus);
		const store = Alpine.store("deliverability");

		try {
			const response = await fetch(url, {
				method: "GET",
				headers: {
					"X-Requested-With": "XMLHttpRequest",
					"Cache-Control": "no-cache",
				},
				signal: AbortSignal.timeout(10000),
			});
			if (!response.ok)
				throw new Error(`HTTP ${response.status}: ${response.statusText}`);
			const json = await response.json();
			if (!json.success)
				throw new Error(
					json.message || "Server returned unsuccessful response"
				);

			updateInternalDeliverabilityMap(json.data || [], skus);
			store?.clearError();
		} catch (error) {
			console.error(
				`[DService_FETCH_FAIL] Attempt ${
					retryCount + 1
				} for '${sourceCode}' failed:`,
				error.message
			);
			if (retryCount < RETRY_ATTEMPTS - 1) {
				await new Promise((resolve) => setTimeout(resolve, RETRY_DELAY));
				return performFetchLogic(sourceCode, skus, retryCount + 1); // Recursive call for retry
			} else {
				console.error(
					`[DService_FETCH_FAIL_FINAL] All retries for '${sourceCode}'. Defaulting these SKUs.`
				);
				defaultSpecificSkus(skus); // Default only the SKUs from this failed request
				store?.setError(`Failed to fetch: ${error.message}`);
			}
		}
	}

	function buildFetchUrl(sourceCode, skus) {
		const baseUrl = window.BASE_URL + "stockavailability/deliverability/get";
		const params = new URLSearchParams({ source_code: sourceCode });
		skus.forEach((sku) => {
			params.append("skus[]", sku);
		});
		return `${baseUrl}?${params.toString()}`;
	}

	function updateInternalDeliverabilityMap(deliverableArray, requestedSkus) {
		const localChanges = {};
		const processedInResponse = new Set();

		if (!Array.isArray(deliverableArray)) {
			console.warn(
				"[DService_MAP] Invalid API response data, defaulting requested SKUs."
			);
			defaultSpecificSkus(requestedSkus); // Default only the SKUs that were part of this request
			return;
		}

		deliverableArray.forEach((item) => {
			if (item?.sku && typeof item.deliverable === "string") {
				deliverableMap[item.sku] = item.deliverable;
				localChanges[item.sku] = item.deliverable;
				processedInResponse.add(item.sku);
			}
		});

		requestedSkus.forEach((sku) => {
			if (!processedInResponse.has(sku)) {
				// console.warn(`[DService_MAP] No data for SKU: ${sku} in response, defaulting to Yes.`);
				deliverableMap[sku] = "Yes"; // Default missing SKUs from this batch
				localChanges[sku] = "Yes";
			}
		});

		const store = Alpine.store("deliverability");
		if (store) {
			// Merge changes into the store map to preserve existing data for other SKUs
			// and ensure reactivity for the updated ones.
			store.map = { ...store.map, ...localChanges };
		}
	}

	function defaultSpecificSkus(skusToDefault) {
		// For defaulting only specific SKUs on error
		const localChanges = {};
		skusToDefault.forEach((sku) => {
			deliverableMap[sku] = "Yes";
			localChanges[sku] = "Yes";
		});
		const store = Alpine.store("deliverability");
		if (store) {
			store.map = { ...store.map, ...localChanges };
		}
	}

	function registerSku(sku) {
		if (!sku || typeof sku !== "string") return;
		const isNewWatch = !watchedSkus.has(sku);
		watchedSkus.add(sku);

		const store = Alpine.store("deliverability");
		if (isNewWatch) {
			// Only process if it's a genuinely new SKU to the watched set
			if (!deliverableMap.hasOwnProperty(sku)) {
				// If no data exists yet (e.g. first time seeing this SKU)
				deliverableMap[sku] = "Yes"; // Default it
				if (store) store.map = { ...store.map, [sku]: "Yes" }; // Update store reactively
			}
			// If a source code is active, fetch for this newly registered SKU
			if (
				store &&
				store.selected_source_code &&
				store.selected_source_code.trim() !== ""
			) {
				fetchData(store.selected_source_code, [sku]);
			}
		}
	}

	function registerSkus(skuArray) {
		if (!Array.isArray(skuArray)) return;
		const newSkusForPotentialFetch = [];
		const newDefaults = {};
		skuArray.forEach((sku) => {
			if (sku && typeof sku === "string" && !watchedSkus.has(sku)) {
				watchedSkus.add(sku);
				if (!deliverableMap.hasOwnProperty(sku)) {
					deliverableMap[sku] = "Yes";
					newDefaults[sku] = "Yes";
				}
				newSkusForPotentialFetch.push(sku);
			}
		});

		if (Object.keys(newDefaults).length > 0) {
			const store = Alpine.store("deliverability");
			if (store) store.map = { ...store.map, ...newDefaults };
		}
		if (newSkusForPotentialFetch.length > 0) {
			const store = Alpine.store("deliverability");
			if (
				store &&
				store.selected_source_code &&
				store.selected_source_code.trim() !== ""
			) {
				fetchData(store.selected_source_code, newSkusForPotentialFetch);
			}
		}
	}

	window.DeliverabilityService = {
		initService, // Renamed
		isDeliverable: (sku) => deliverableMap[sku] === "Yes",
		registerSku,
		registerSkus,
		// notifySourceCodeChanged is REMOVED as we revert to effect-driven
		refreshData: () => {
			/* ... unchanged ... */
		},
	};
	initService();
})();
