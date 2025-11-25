/**
 * Background Tasks Button Management
 * Handles the background tasks button display, auto-polling, and interaction
 */

(function() {
  'use strict';

  // Configuration
  const POLL_INTERVAL_NORMAL = 60000; // 60 seconds
  const POLL_INTERVAL_ACTIVE = 10000; // 10 seconds when tasks > 0

  // State
  let pollInterval = null;
  let previewTimeout = null;
  let previewDiv = null;
  let currentTaskCount = 0;
  let isPollingActive = false; // Track if we're in active polling mode
  let previousAICount = 0;
  let previousVariantsCount = 0;

  /**
   * Get background tasks preview from backend
   */
  async function getBackgroundTasksPreview() {
    try {
      const res = await fetch('process_background_tasks.php?preview=1');
      if (!res.ok) return null;
      return await res.json();
    } catch (error) {
      console.error('Preview error:', error);
      return null;
    }
  }

  /**
   * Process background tasks (execute them)
   */
  async function processBackgroundTasks() {
    try {
      const formData = new FormData();
      formData.set('async', '0');
      
      const res = await fetch('process_background_tasks.php', {
        method: 'POST',
        body: formData
      });
      
      if (!res.ok) {
        throw new Error(`HTTP ${res.status}`);
      }
      
      const data = await res.json();
      return data;
    } catch (error) {
      console.error('Background tasks error:', error);
      throw error;
    }
  }

  /**
   * Trigger async background task processing (fire-and-forget)
   * This starts background processing without waiting for completion
   */
  async function triggerBackgroundTasks() {
    try {
      const formData = new FormData();
      formData.set('async', '1');
      
      // Fire-and-forget: don't wait for response, just trigger it
      fetch('process_background_tasks.php', {
        method: 'POST',
        body: formData
      }).catch(error => {
        // Silently ignore errors - this is fire-and-forget
        console.debug('Background task trigger error (ignored):', error);
      });
    } catch (error) {
      // Silently ignore errors - this is fire-and-forget
      console.debug('Background task trigger error (ignored):', error);
    }
  }

  // Expose triggerBackgroundTasks globally so it can be called from index.html
  window.triggerBackgroundTasks = triggerBackgroundTasks;

  /**
   * Calculate total task count from preview summary (only AI and Variants, excluding Gallery)
   */
  function calculateTaskCount(summary) {
    if (!summary) return 0;
    const variants = summary.variants || 0;
    const ai = summary.ai || 0;
    return variants + ai;
  }

  /**
   * Update button display with task count
   */
  function updateButtonDisplay(btn, count) {
    currentTaskCount = count;
    btn.textContent = count.toString();
    
    if (count > 0) {
      btn.classList.add('has-tasks');
    } else {
      btn.classList.remove('has-tasks');
    }
  }

  /**
   * Reload images and variants for paintings affected by completed AI/variant tasks
   * Updates only paintings that have active AI/variant generation tasks
   */
  async function reloadAffectedPaintings() {
    // Check if we're on index.html
    const isIndexPage = window.location.pathname.includes('index.html') || 
                        (window.location.pathname.endsWith('/admin/') || window.location.pathname.endsWith('/admin'));
    
    if (!isIndexPage) return;

    // Check if required functions exist
    if (typeof window.updateImageRow !== 'function') {
      // Fallback to refresh if updateImageRow is not available
      if (typeof window.refresh === 'function') {
        try {
          await window.refresh();
        } catch (error) {
          console.error('Error refreshing images:', error);
        }
      }
      return;
    }

    try {
      // Fetch image data to check which paintings have active tasks
      if (typeof window.fetchImages !== 'function') {
        // Fallback to refresh if fetchImages is not available
        if (typeof window.refresh === 'function') {
          await window.refresh();
        }
        return;
      }

      const { groups } = await window.fetchImages();
      if (!groups || groups.length === 0) {
        return;
      }

      // Find paintings with active AI/variant tasks
      // Update entire painting if ANY AI generation is in progress (corners, form, or variants)
      const paintingsToUpdate = new Set();
      
      for (const group of groups) {
        const meta = group.meta || {};
        const aiCorners = meta.ai_corners || {};
        const aiFillForm = meta.ai_fill_form || {};
        const aiPaintingVariants = meta.ai_painting_variants || {};
        
        // Check corners status - if wanted or in_progress, update entire painting
        const cornersStatus = aiCorners.status || null;
        const hasActiveCorners = cornersStatus && ['wanted', 'in_progress'].includes(cornersStatus);
        
        // Check form status - if wanted or in_progress, update entire painting
        const formStatus = aiFillForm.status || null;
        const hasActiveForm = formStatus && ['wanted', 'in_progress'].includes(formStatus);
        
        // Check variant regeneration status - if wanted or in_progress, update entire painting
        const variantRegenStatus = aiPaintingVariants.regeneration_status || null;
        const hasActiveVariantRegen = variantRegenStatus && ['wanted', 'in_progress'].includes(variantRegenStatus);
        
        // Check if there are active variants (variants being tracked/generated)
        const hasActiveVariantsList = aiPaintingVariants.active_variants && 
                                     Array.isArray(aiPaintingVariants.active_variants) && 
                                     aiPaintingVariants.active_variants.length > 0;
        
        // Check individual variants - if ANY variant is wanted or in_progress, update entire painting
        let hasActiveVariants = false;
        if (aiPaintingVariants.variants && typeof aiPaintingVariants.variants === 'object') {
          for (const variantName in aiPaintingVariants.variants) {
            const variant = aiPaintingVariants.variants[variantName];
            const variantStatus = variant.status || null;
            if (variantStatus && ['wanted', 'in_progress'].includes(variantStatus)) {
              hasActiveVariants = true;
              break;
            }
          }
        }
        
        // If ANY AI generation is active for this painting, update the ENTIRE painting
        // This ensures all images (main image, variants), and forms are updated together
        // The painting-level check means if ANY variant/form/corners is active, update everything
        if (hasActiveCorners || hasActiveForm || hasActiveVariantRegen || hasActiveVariants || hasActiveVariantsList) {
          paintingsToUpdate.add(group.base);
        }
      }

      // Only update paintings with active AI generation
      if (paintingsToUpdate.size === 0) {
        return; // No active AI generation, nothing to update
      }

      // Update each painting with active AI generation - update entire painting row
      const updatePromises = Array.from(paintingsToUpdate).map(baseName => {
        // Check if row exists in DOM
        const row = document.getElementById(`painting-${baseName}`);
        if (!row) {
          return Promise.resolve(); // Row doesn't exist, skip
        }
        
        // Update entire painting row - this will refresh all images, variants, and forms
        // forceFormUpdate: false means forms are only updated if locked (in generation)
        if (typeof window.updateImageRow === 'function') {
          return window.updateImageRow(baseName, { forceFormUpdate: false });
        }
        return Promise.resolve();
      });

      await Promise.all(updatePromises);
    } catch (error) {
      console.error('Error reloading affected paintings:', error);
      // Fallback to full refresh on error
      if (typeof window.refresh === 'function') {
        try {
          await window.refresh();
        } catch (refreshError) {
          console.error('Error in fallback refresh:', refreshError);
        }
      }
    }
  }

  /**
   * Update button and tooltip with latest task data
   */
  async function updateTaskStatus() {
    const btn = document.getElementById('bgTasksBtn');
    if (!btn) return;

    try {
      const preview = await getBackgroundTasksPreview();
      if (!preview || !preview.summary) {
        updateButtonDisplay(btn, 0);
        scheduleNextPoll(false);
        previousAICount = 0;
        previousVariantsCount = 0;
        return;
      }

      const currentAICount = preview.summary.ai || 0;
      const currentVariantsCount = preview.summary.variants || 0;
      const count = calculateTaskCount(preview.summary);
      
      // Check if AI or variants count decreased (tasks completed)
      const aiDecreased = currentAICount < previousAICount;
      const variantsDecreased = currentVariantsCount < previousVariantsCount;
      
      // Check if there are active tasks (for periodic updates)
      const hasActiveTasks = count > 0;
      
      // Update previous counts
      previousAICount = currentAICount;
      previousVariantsCount = currentVariantsCount;
      
      updateButtonDisplay(btn, count);

      // Update tooltip
      const tooltipText = `Background Tasks\nVariants: ${preview.summary.variants || 0}\nAI: ${preview.summary.ai || 0}\nGallery: ${preview.summary.gallery || 0}`;
      btn.setAttribute('title', tooltipText);

      // Reload affected paintings if:
      // 1. Tasks completed (counts decreased) - immediate update
      // 2. Active tasks exist - periodic update to refresh variant images and locked forms
      if (aiDecreased || variantsDecreased) {
        // Small delay to ensure backend has finished processing
        setTimeout(() => {
          reloadAffectedPaintings();
        }, 500);
      } else if (hasActiveTasks) {
        // Periodic update for active tasks (throttled to avoid too frequent updates)
        // Update every ~30 seconds when tasks are active
        const now = Date.now();
        const throttleInterval = 30000; // 30 seconds
        if (!window.lastAutoUpdate || (now - window.lastAutoUpdate) >= throttleInterval) {
          window.lastAutoUpdate = now;
          // Small delay to avoid conflicts
          setTimeout(() => {
            reloadAffectedPaintings();
          }, 1000);
        }
      }

      // Adjust polling interval based on task count (only AI and Variants, not Gallery)
      scheduleNextPoll(count > 0);
    } catch (error) {
      console.error('Error updating task status:', error);
      scheduleNextPoll(false);
    }
  }

  /**
   * Schedule next poll based on whether there are active tasks
   */
  function scheduleNextPoll(hasActiveTasks) {
    // Only reschedule if the state changed
    if (hasActiveTasks === isPollingActive && pollInterval) {
      return; // Already polling at the correct interval
    }

    // Clear existing interval
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }

    // Update state
    isPollingActive = hasActiveTasks;

    // Schedule next poll
    const interval = hasActiveTasks ? POLL_INTERVAL_ACTIVE : POLL_INTERVAL_NORMAL;
    pollInterval = setInterval(() => {
      updateTaskStatus();
    }, interval);
  }

  /**
   * Show toast notification (uses existing showToast function if available)
   */
  function showToast(message, type = 'info') {
    // Try to use existing showToast function from the page
    if (typeof window.showToast === 'function') {
      window.showToast(message, type);
      return;
    }

    // Fallback: create toast container if it doesn't exist
    let container = document.getElementById('toastContainer');
    if (!container) {
      container = document.createElement('div');
      container.id = 'toastContainer';
      container.className = 'toast-container';
      container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000; display: flex; flex-direction: column; gap: 8px;';
      document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    toast.style.cssText = 'background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 12px 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 300px; max-width: 400px; animation: toastSlideIn 0.3s ease-out;';
    if (type === 'success') {
      toast.style.borderColor = '#28a745';
      toast.style.background = '#f6fffa';
      toast.style.color = '#1f6b3a';
    } else if (type === 'error') {
      toast.style.borderColor = '#dc3545';
      toast.style.background = '#fff5f5';
      toast.style.color = '#8a1f1f';
    }
    container.appendChild(toast);

    setTimeout(() => {
      toast.classList.add('hiding');
      setTimeout(() => {
        if (toast.parentNode) {
          toast.parentNode.removeChild(toast);
        }
      }, 300);
    }, 3000);
  }

  /**
   * Initialize background tasks button
   */
  function initBackgroundTasksButton() {
    const btn = document.getElementById('bgTasksBtn');
    if (!btn) return;

    // Initialize previous counts from initial status
    getBackgroundTasksPreview().then(preview => {
      if (preview && preview.summary) {
        previousAICount = preview.summary.ai || 0;
        previousVariantsCount = preview.summary.variants || 0;
      }
    }).catch(() => {
      // Ignore errors on initial load
    });

    // Initial update (this will also start the polling)
    updateTaskStatus();

    // Setup hover tooltip
    btn.addEventListener('mouseenter', async function() {
      previewTimeout = setTimeout(async () => {
        const preview = await getBackgroundTasksPreview();
        if (!preview || !preview.summary) return;
        
        previewDiv = document.createElement('div');
        previewDiv.style.cssText = 'position: absolute; top: 100%; right: 0; margin-top: 4px; background: #fff; border: 1px solid #888; border-radius: 6px; padding: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; min-width: 200px; font-size: 12px; white-space: nowrap;';
        previewDiv.innerHTML = `
          <div style="font-weight: 600; margin-bottom: 8px; color: #333;">Background Tasks</div>
          <div style="color: #666;">Variants: ${preview.summary.variants || 0}</div>
          <div style="color: #666;">AI: ${preview.summary.ai || 0}</div>
          <div style="color: #666;">Gallery: ${preview.summary.gallery || 0}</div>
        `;
        btn.style.position = 'relative';
        if (btn.parentElement) {
          btn.parentElement.style.position = 'relative';
          btn.parentElement.appendChild(previewDiv);
        }
      }, 300);
    });
    
    btn.addEventListener('mouseleave', function() {
      clearTimeout(previewTimeout);
      if (previewDiv && previewDiv.parentElement) {
        previewDiv.parentElement.removeChild(previewDiv);
        previewDiv = null;
      }
    });
    
    // Setup click handler
    btn.addEventListener('click', async function() {
      btn.disabled = true;
      const originalText = btn.textContent;
      btn.textContent = '...';
      
      try {
        const result = await processBackgroundTasks();
        showToast(`Background tasks processed: ${result.processed?.length || 0} processed, ${result.errors?.length || 0} errors`, result.ok ? 'success' : 'error');
        
        // Update status after processing
        setTimeout(() => {
          updateTaskStatus();
        }, 500);
      } catch (error) {
        showToast('Fehler beim Verarbeiten der Background Tasks', 'error');
      } finally {
        btn.disabled = false;
        // Text will be updated by updateTaskStatus
        updateTaskStatus();
      }
    });
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBackgroundTasksButton);
  } else {
    initBackgroundTasksButton();
  }

  // Cleanup on page unload
  window.addEventListener('beforeunload', function() {
    if (pollInterval) {
      clearInterval(pollInterval);
    }
    if (previewTimeout) {
      clearTimeout(previewTimeout);
    }
  });
})();

