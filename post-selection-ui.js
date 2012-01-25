(function(){
  jQuery(function(){
    var setVal, clearVal;
    if (!jQuery('<input placeholder="1" />')[0].placeholder) {
      setVal = function(){
        var $this;
        $this = jQuery(this);
        if (!$this.val()) {
          $this.val($this.attr('placeholder'));
          $this.addClass('psu-placeholder');
        }
      };
      clearVal = function(){
        var $this;
        $this = jQuery(this);
        if ($this.hasClass('psu-placeholder')) {
          $this.val('');
          $this.removeClass('psu-placeholder');
        }
      };
      jQuery('.psu-search input[placeholder]').each(setVal).focus(clearVal).blur(setVal);
    }
		
		
    return jQuery('.psu-box').each(function(){
      var $selectedIDs, $selectionBox, $selectedPosts, $spinner, max_posts, is_full, post_type, update_box, ajax_request, add_post, remove_all_posts, remove_post, switch_to_tab, PostsTab, searchTab, listTab, $searchInput;
			
      $selectionBox = jQuery(this);
			$selectedIDs = $selectionBox.children('input');
      $selectedPosts = $selectionBox.find('.psu-selected');
			max_posts = parseInt($selectionBox.data('cardinality'));
			post_type = $selectionBox.data('post_type');
			
      $spinner = jQuery('<img>', {
        'src': PostSelectionUI.spinner,
        'class': 'psu-spinner'
      });
			
			remove_all_posts = function(ev){
        if (!confirm(PostSelectionUI.clearConfirmMessage)) {
          return false;
        }
				
				$selectedPosts.find('tbody').html('');
				
				update_box();
				ev.preventDefault();
      };
			
			remove_post = function(ev){
        var $self;
        $self = jQuery(ev.target);
        $self.closest('tr').remove();
				update_box();
				ev.preventDefault();
      };
			
			add_post = function(ev) {
				var $self, $tr;
				if(is_full())
					return false;
        $self = jQuery(ev.target);
				$tr = $self.closest('tr');
				$tr.appendTo($selectedPosts).append('<td class="psu-col-order">&nbsp;</td>').find('td.psu-col-create').removeClass('psu-col-create').addClass('psu-col-delete').find('a').attr('title', 'Remove');
				
				update_box();
				ev.preventDefault();
			}
			
			update_box = function() {
				//update id list
				ids = [];
				$selectedPosts.find('tbody tr').each(function(){
					ids.push(jQuery(this).data('post_id'));
				});
				$selectedIDs.val(ids.join(','));
				
				//update views
				if (0 == $selectedPosts.find('tbody tr').length) {
					$selectedPosts.hide();
				} else {
					$selectedPosts.show();
				}
				
				if (is_full()) {
					$selectionBox.hide();
				} else {
					$selectionBox.show();
				}
			};

			is_full = function() {
				return (max_posts > 0 && $selectedPosts.find('tbody tr').length >= max_posts);
			};
			
			
			switch_to_tab = function(){
        var $tab;
        $tab = jQuery(this);
        $selectionBox.find('.wp-tab-bar li').removeClass('wp-tab-active');
        $tab.addClass('wp-tab-active');
        $selectionBox.find('.tabs-panel').hide().end().find($tab.data('ref')).show().find(':text').focus();
        return false;
      };
			
			
			$selectionBox.delegate('th.psu-col-delete a', 'click', remove_all_posts).delegate('td.psu-col-delete a', 'click', remove_post).delegate('td.psu-col-create a', 'click', add_post).delegate('.wp-tab-bar li', 'click', switch_to_tab);
			
			
			
      ajax_request = function(data, callback){
        data.action = 'psu_box';
        data._ajax_nonce = PostSelectionUI.nonce;
        data.post_type = post_type;
				data.exclude = $selectedIDs.val();
        return jQuery.getJSON(ajaxurl + '?' + jQuery.param(data), callback);
      };
			
			$selectedPosts.find('tbody').sortable({
				handle: 'td.psu-col-order',
				helper: function(e, ui){
					ui.children().each(function(){
						var $this;
						$this = jQuery(this);
						return $this.width($this.width());
					});
					return ui;
				},
				update: update_box
			});
          
      PostsTab = (function(){
        PostsTab.displayName = 'PostsTab';
        var prototype = PostsTab.prototype, constructor = PostsTab;
        function PostsTab(selector){
          this.tab = $selectionBox.find(selector);
          this.init_pagination_data();
          this.tab.delegate('.psu-prev, .psu-next', 'click', __bind(this, this.change_page));
          this.data = {};
        }
        prototype.init_pagination_data = function(){
          this.current_page = this.tab.find('.psu-current').data('num') || 1;
          return this.total_pages = this.tab.find('.psu-total').data('num') || 1;
        };
        prototype.change_page = function(ev){
          var $navButton, new_page;
          $navButton = jQuery(ev.target);
          new_page = this.current_page;
          if ($navButton.hasClass('inactive')) {
            return false;
          }
          if ($navButton.hasClass('psu-prev')) {
            new_page--;
          } else {
            new_page++;
          }
          this.find_posts(new_page);
          return false;
        };
        
				prototype.find_posts = function(new_page){
          this.data.paged = new_page
            ? new_page > this.total_pages ? this.current_page : new_page
            : this.current_page;
          $spinner.appendTo(this.tab.find('.psu-navigation'));
          return ajax_request(this.data, __bind(this, this.update_rows));
        };
				
				
        prototype.update_rows = function(response){
          $spinner.remove();
          this.tab.find('.psu-results, .psu-navigation, .psu-notice').remove();
          if (!response.rows) {
            return this.tab.append(jQuery('<div class="psu-notice">').html(response.msg));
          } else {
            this.tab.append(response.rows);
            return this.init_pagination_data();
          }
        };
        return PostsTab;
      }());
						
      searchTab = new PostsTab('.psu-tab-search');
      listTab = new PostsTab('.psu-tab-list');
      $searchInput = $selectionBox.find('.psu-tab-search :text');
      $searchInput.keypress(function(ev){
        if (13 === ev.keyCode) {
          return false;
        }
      }).keyup(function(ev){
        var delayed;
        if (undefined !== delayed) {
          clearTimeout(delayed);
        }
        return delayed = setTimeout(function(){
          var searchStr;
          searchStr = $searchInput.val();
          if ('' == searchStr || searchStr === searchTab.data.s) {
            return;
          }
          searchTab.data.s = searchStr;
          $spinner.insertAfter($searchInput).show();
          return searchTab.find_posts(1);
        }, 400);
      });


			update_box(); //make sure inputs match what screen currently shows

    });
  });
  function __bind(me, fn){ return function(){ return fn.apply(me, arguments) } }
}).call(this);
