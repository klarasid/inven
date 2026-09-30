export type Id = number | string
export type Values = Record<string, string | number | null>
export interface Config {view:string; query:Record<string,string>; write:boolean; uid:number; api:string; watch:string; inventory:string; today:string; viewer?:string}
export interface Options {csrf:string; inventoryCsrf:string; users:{user_id:Id;realname:string}[]; rooms:{id:Id;room_name:string;slims_location_id:string}[]; libraries:{location_id:string;location_name:string}[]; templates:{id:Id;name:string;source_id:Id|null}[]; frequencies:Record<string,string>; outcomes:Record<string,string>; priorities:Record<string,string>}
export interface Snapshot {room_name:string; library_name:string; template_name:string; template_id:Id|null; room_id:Id; assignee:{id:Id;name:string}; items:ChecklistItem[]}
export interface ChecklistItem {group:string;object:string;instruction:string;item_id?:Id;item_name?:string;item_code?:string}
export interface Inspection {id:Id;version:number;status:string;due_date:string;performed_date:string|null;notes:string;kind:string;reason:string;parent_id:Id|null;location_id:Id|null;examiner_id:Id|null;examiner_name:string|null}
export interface Result {id:Id;snapshot:ChecklistItem;outcome:string;notes:string;assignee_id:Id|null;priority:string|null;deadline:string|null}
export interface Finding {id:Id;inspection_id:Id;result_id:Id;version:number;status:string;assignee_id:Id;assignee_name:string;deadline:string;priority:string}
export interface Photo {id:Id;url:string|null;result_id?:Id;action_id?:Id}
export interface WorkAction {id:Id;finding_id:Id;submitted_at:string|null;kind:string;description:string;performed_date:string;cost:string|null;actor_name:string}
export interface Event {id:Id;finding_id:Id|null;event:string;actor_name:string;notes:string;created_at:string}
export interface Document {inspection:Inspection;snapshot:Snapshot;results:Result[];finding:Finding|null;findings:Finding[];actions:WorkAction[];events:Event[];photos:Photo[]}
export interface Route {view:string;record?:Id;room?:Id;kind?:string;owner?:string;history?:string;q?:string;library?:string;page?:number;from?:string;to?:string;parent_id?:Id;template_id?:Id;replaces_id?:Id;[key:string]:string|number|undefined}
export interface Page<T> {rows:T[];total:number;page:number;pages:number;room?:Values;conditions?:Record<string,string>}
export interface TaskRow extends Inspection {snapshot:Snapshot;result_snapshot?:ChecklistItem;assignee_name?:string;deadline?:string;priority?:string;reporter_id?:Id|null;reporter_name?:string|null}
export interface Schedule {id:Id;version:number;location_id:Id|null;template_id:Id;assignee_id:Id;assignee_name:string;frequency:string;start_date:string;end_date:string|null;active:Id;snapshot:Snapshot}
export interface Template {id:Id;name:string;source_id:Id|null;items:ChecklistItem[]}
export interface Summary {counts:{finalized:number;late:number;routine_final:number;incidental:number;historical:number};findings:{open:number;closed:number;late:number};unformed:number;unformed_late:number;planned:number;room_examined:number;room_total:number;item_examined:number;item_applicable:number;item_na:number;missing_rooms:{room_name:string;location_name:string}[]}
export interface Reply {ok:boolean;message?:string;errors?:Record<string,string>;code?:string;record?:Id;location_id?:Id;document?:{id:Id;version:number;status:string;photos:Photo[]};generated?:number;more?:boolean;url?:string;data?:unknown}
export interface Counts {inspections:{mine:number;all:number};findings:{mine:number;all:number};review:{mine:number;all:number};history:number;templates:number;schedules:number;rooms:number;items:number}
