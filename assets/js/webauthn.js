function arrayBufferToBase64(buffer){
  let binary='';
  const bytes=new Uint8Array(buffer);
  for(let i=0;i<bytes.byteLength;i++) binary+=String.fromCharCode(bytes[i]);
  return btoa(binary);
}
function recursiveBase64StrToArrayBuffer(obj){
  const prefix='=?BINARY?B?';
  const suffix='?=';
  if(typeof obj==='object'){
    for(const key in obj){
      if(typeof obj[key]==='string'){
        let str=obj[key];
        if(str.substring(0,prefix.length)===prefix && str.substring(str.length-suffix.length)===suffix){
          str=str.substring(prefix.length,str.length-suffix.length);
          const bin=atob(str);
          const len=bin.length;
          const bytes=new Uint8Array(len);
          for(let i=0;i<len;i++) bytes[i]=bin.charCodeAt(i);
          obj[key]=bytes.buffer;
        }
      }else{
        recursiveBase64StrToArrayBuffer(obj[key]);
      }
    }
  }
}
function base64ToArrayBuffer(base64){
  const bin=atob(base64);
  const len=bin.length;
  const bytes=new Uint8Array(len);
  for(let i=0;i<len;i++) bytes[i]=bin.charCodeAt(i);
  return bytes.buffer;
}
